<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentExtraction extends Model
{
    protected $fillable = [
        'verification_document_id', 'provider', 'provider_version',
        'document_type', 'country', 'fields', 'field_confidences',
        'extraction_confidence', 'signals', 'raw_text_encrypted', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'encrypted:array',
            'field_confidences' => 'array',
            'signals' => 'array',
            'extraction_confidence' => 'float',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(VerificationDocument::class, 'verification_document_id');
    }
}
