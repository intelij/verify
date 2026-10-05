<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'public_id', 'verification_id', 'consumer_id', 'event', 'url',
        'payload', 'status', 'attempts', 'max_attempts', 'next_attempt_at',
        'last_response_code', 'last_error', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $w) {
            $w->public_id ??= 'evt_' . Str::ulid()->toBase32();
        });
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }
}
