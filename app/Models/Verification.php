<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Verification extends Model
{
    protected $fillable = [
        'public_id', 'consumer_id', 'verification_subject_id', 'policy_code',
        'external_reference', 'idempotency_key', 'status', 'reason_codes',
        'confidence', 'attempts', 'expected_document_type', 'callback_url',
        'documents_due_at', 'started_processing_at', 'completed_at',
        'verified_until', 'last_error_at', 'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'reason_codes' => 'array',
            'confidence' => 'float',
            'documents_due_at' => 'datetime',
            'started_processing_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_until' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $v) {
            $v->public_id ??= 'ver_' . Str::ulid()->toBase32();
        });
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(VerificationSubject::class, 'verification_subject_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VerificationDocument::class);
    }

    public function fieldResults(): HasMany
    {
        return $this->hasMany(VerificationFieldResult::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(VerificationEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(VerificationReview::class);
    }

    public function providerAttempts(): HasMany
    {
        return $this->hasMany(ProviderAttempt::class);
    }

    public function statusEnum(): VerificationStatus
    {
        return VerificationStatus::from($this->status);
    }

    public function isTerminal(): bool
    {
        return $this->statusEnum()->isTerminal();
    }

    public function recordEvent(string $event, array $data = [], string $actorType = 'system', ?string $actorId = null): VerificationEvent
    {
        return $this->events()->create([
            'public_id' => 'evt_' . Str::ulid()->toBase32(),
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'data' => $data,
        ]);
    }

    public function transitionTo(VerificationStatus $status, ?array $reasonCodes = null, ?float $confidence = null): void
    {
        $this->status = $status->value;
        if ($reasonCodes !== null) {
            $this->reason_codes = $reasonCodes;
        }
        if ($confidence !== null) {
            $this->confidence = $confidence;
        }
        if ($status->isTerminal()) {
            $this->completed_at ??= now();
        }
        $this->save();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
