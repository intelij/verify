<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationFieldResult extends Model
{
    protected $fillable = [
        'verification_id', 'field', 'expected_masked', 'provided',
        'match', 'confidence', 'reason_code',
    ];

    protected function casts(): array
    {
        return [
            'provided' => 'bool',
            'match' => 'bool',
            'confidence' => 'float',
        ];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }
}
