<?php

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use App\Models\Verification;
use App\Models\WebhookDelivery;

/**
 * Creates signed webhook deliveries for consumer-configured callbacks.
 * Payloads carry no PII — consumers fetch detail via the polling API.
 */
class WebhookService
{
    public function dispatch(Verification $verification, string $event): ?WebhookDelivery
    {
        $consumer = $verification->consumer;
        $url = $verification->callback_url ?? $consumer->config['webhook_url'] ?? null;
        if (! $url) {
            return null;
        }

        // Idempotent: one delivery per (verification, event, status).
        $payload = [
            'event' => $event,
            'verification_id' => $verification->public_id,
            'external_reference' => $verification->external_reference,
            'status' => $verification->status,
            'occurred_at' => now()->toIso8601String(),
        ];

        $existing = WebhookDelivery::where('verification_id', $verification->id)
            ->where('event', $event)
            ->where('payload->status', $verification->status)
            ->first();
        if ($existing) {
            return $existing;
        }

        $delivery = WebhookDelivery::create([
            'verification_id' => $verification->id,
            'consumer_id' => $consumer->id,
            'event' => $event,
            'url' => $url,
            'payload' => $payload,
            'status' => 'pending',
            'max_attempts' => (int) config('verification.webhook.max_attempts', 8),
            'next_attempt_at' => now(),
        ]);

        DeliverWebhookJob::dispatch($delivery->id)->onQueue('webhooks');

        return $delivery;
    }

    /**
     * HMAC-SHA256 signature headers: t={ts},v1={hmac}.
     * Consumers verify: hmac_sha256(webhook_secret, "{ts}.{body}").
     */
    public function sign(string $secret, string $body): array
    {
        $ts = time();
        return [
            'timestamp' => $ts,
            'signature' => 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, $secret),
        ];
    }

    public function verify(string $secret, string $body, string $signatureHeader, ?int $ttl = null): bool
    {
        if (! preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $signatureHeader, $m)) {
            return false;
        }
        $ttl ??= (int) config('verification.webhook.signature_ttl_seconds', 300);
        if (abs(time() - (int) $m[1]) > $ttl) {
            return false; // replay window exceeded
        }
        $expected = hash_hmac('sha256', $m[1] . '.' . $body, $secret);
        return hash_equals($expected, $m[2]);
    }
}
