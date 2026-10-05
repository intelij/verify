<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VerificationDocument extends Model
{
    protected $fillable = [
        'public_id', 'verification_id', 'declared_type', 'detected_type',
        'detected_country', 'side', 'storage_disk', 'storage_path',
        'original_filename', 'mime_type', 'size_bytes', 'sha256',
        'page_count', 'status', 'quality', 'quality_score',
    ];

    protected function casts(): array
    {
        return [
            'quality' => 'array',
            'quality_score' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $d) {
            $d->public_id ??= 'doc_' . Str::ulid()->toBase32();
        });
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function extractions(): HasMany
    {
        return $this->hasMany(DocumentExtraction::class);
    }

    public function absolutePath(): ?string
    {
        $disk = \Storage::disk($this->storage_disk);
        return method_exists($disk, 'path') ? $disk->path($this->storage_path) : null;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
