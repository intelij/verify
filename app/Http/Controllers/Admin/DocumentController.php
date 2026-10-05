<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VerificationDocument;
use Illuminate\Support\Facades\Storage;

/**
 * Admin-only document viewing. Access is audited on the parent verification.
 */
class DocumentController extends Controller
{
    public function view(VerificationDocument $document)
    {
        $document->verification->recordEvent('document.viewed', [
            'document' => $document->public_id,
        ], 'admin', (string) auth()->id());

        return Storage::disk($document->storage_disk)->response(
            $document->storage_path,
            $document->original_filename,
            ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
