<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationPolicy extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'allowed_document_types',
        'required_fields', 'optional_fields', 'verify_threshold',
        'review_threshold', 'min_quality', 'min_extraction_confidence',
        'require_not_expired', 'require_document_number',
        'require_authenticity_pass', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'allowed_document_types' => 'array',
            'required_fields' => 'array',
            'optional_fields' => 'array',
            'verify_threshold' => 'float',
            'review_threshold' => 'float',
            'min_quality' => 'float',
            'min_extraction_confidence' => 'float',
            'require_not_expired' => 'bool',
            'require_document_number' => 'bool',
            'require_authenticity_pass' => 'bool',
            'enabled' => 'bool',
        ];
    }
}
