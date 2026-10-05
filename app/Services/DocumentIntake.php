<?php

namespace App\Services;

use App\Models\Verification;
use App\Models\VerificationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Secure document intake: magic-byte validation, size limits, private
 * storage, hash dedup. Never trusts client-supplied MIME types.
 */
class DocumentIntake
{
    private const MAGIC = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89PNG\x0D\x0A\x1A\x0A"],
        'image/webp' => ['RIFF'],
        'application/pdf' => ['%PDF-'],
        'image/heic' => ['ftypheic', 'ftypheix', 'ftypmif1', 'ftypmsf1'],
        'image/heif' => ['ftypheic', 'ftypheix', 'ftypmif1', 'ftypmsf1'],
    ];

    /**
     * @throws DocumentValidationException
     */
    public function store(Verification $verification, UploadedFile|string $fileOrPath, ?string $declaredType = null, ?string $originalName = null, ?string $side = null): VerificationDocument
    {
        $disk = config('verification.storage_disk', 'documents');
        $tmpPath = $fileOrPath instanceof UploadedFile ? $fileOrPath->getRealPath() : $fileOrPath;
        $originalName ??= $fileOrPath instanceof UploadedFile ? $fileOrPath->getClientOriginalName() : basename($fileOrPath);

        $mime = $this->sniffMime($tmpPath);
        if (! in_array($mime, config('verification.allowed_mimes'), true)) {
            throw new DocumentValidationException('unsupported_file_type', "Unsupported file type: {$mime}");
        }

        $size = filesize($tmpPath) ?: 0;
        $max = (int) config('verification.max_upload_bytes');
        if ($size === 0) {
            throw new DocumentValidationException('empty_file', 'Uploaded file is empty');
        }
        if ($size > $max) {
            throw new DocumentValidationException('file_too_large', 'File exceeds maximum size of ' . round($max / 1048576) . 'MB');
        }

        $sha = hash_file('sha256', $tmpPath);

        $relative = "verifications/{$verification->public_id}/original/" . Str::ulid()->toBase32() . '.' . $this->extensionFor($mime);
        Storage::disk($disk)->put($relative, file_get_contents($tmpPath));

        return $verification->documents()->create([
            'declared_type' => $declaredType,
            'side' => $side,
            'storage_disk' => $disk,
            'storage_path' => $relative,
            'original_filename' => Str::limit((string) $originalName, 255, ''),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => $sha,
            'status' => 'uploaded',
        ]);
    }

    /**
     * Determine real type from magic bytes — not the client-supplied header.
     */
    public function sniffMime(string $path): string
    {
        $head = file_get_contents($path, false, null, 0, 32) ?: '';
        foreach (self::MAGIC as $mime => $signatures) {
            foreach ($signatures as $sig) {
                if (str_starts_with($head, $sig)) {
                    if ($mime === 'image/webp' && substr($head, 8, 4) !== 'WEBP') {
                        continue;
                    }
                    return $mime;
                }
                // HEIC: signature sits at offset 4 inside the ftyp box.
                if (str_starts_with($mime, 'image/hei') && str_contains(substr($head, 4, 12), $sig)) {
                    return 'image/heic';
                }
            }
        }

        return 'application/octet-stream';
    }

    private function extensionFor(string $mime): string
    {
        return [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/heic' => 'heic', 'image/heif' => 'heif', 'application/pdf' => 'pdf',
        ][$mime] ?? 'bin';
    }

    /**
     * Signed temporary access URL (S3) or null — admin view route is the
     * authorized path for local storage.
     */
    public function temporaryUrl(VerificationDocument $doc, int $minutes = 5): ?string
    {
        try {
            return Storage::disk($doc->storage_disk)->temporaryUrl(
                $doc->storage_path, now()->addMinutes($minutes));
        } catch (\Throwable) {
            return null;
        }
    }
}
