<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['public_id', 'verification_id', 'event', 'actor_type', 'actor_id', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }
}
