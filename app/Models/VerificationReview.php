<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationReview extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['verification_id', 'reviewer_id', 'decision', 'notes'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
