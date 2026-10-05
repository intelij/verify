<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Consumer extends Model
{
    protected $fillable = [
        'key_id', 'name', 'slug', 'secret_hash', 'webhook_secret',
        'status', 'rate_limit_per_minute', 'allowed_policies', 'config',
    ];

    protected $hidden = ['secret_hash', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'allowed_policies' => 'array',
            'config' => 'array',
        ];
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(VerificationSubject::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Create a consumer and return the plaintext secret once.
     * @return array{0: self, 1: string}
     */
    public static function provision(string $name, string $slug, ?array $allowedPolicies = null): array
    {
        $secret = 'vfy_' . Str::random(48);
        $consumer = self::create([
            'key_id' => 'key_' . Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => $slug,
            'secret_hash' => hash('sha256', $secret),
            'webhook_secret' => 'whsec_' . Str::random(48),
            'allowed_policies' => $allowedPolicies,
        ]);

        return [$consumer, "{$consumer->key_id}.{$secret}"];
    }

    public static function resolveFromToken(string $token): ?self
    {
        if (! str_contains($token, '.')) {
            return null;
        }
        [$keyId, $secret] = explode('.', $token, 2);
        $consumer = self::where('key_id', $keyId)->first();

        return $consumer && hash_equals($consumer->secret_hash, hash('sha256', $secret)) ? $consumer : null;
    }
}
