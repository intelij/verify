<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'verification_id', 'verification_document_id', 'provider',
        'operation', 'status', 'duration_ms', 'error_code',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
