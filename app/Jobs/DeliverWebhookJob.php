<?php

namespace App\Jobs;

use App\Models\Verification;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Signed webhook delivery with exponential backoff and dead-letter state.
 * Retries are idempotent: consumers may receive duplicates and must dedupe
 * by event id (payload.event + verification_id + status).
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries = 1; // we manage retries manually for scheduled backoff

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookService $webhooks): void
    {
        $delivery = WebhookDelivery::with('consumer')->find($this->deliveryId);
        if (! $delivery || ! in_array($delivery->status, ['pending', 'retrying'], true)) {
            return; // already delivered or dead-lettered
        }

        $secret = $delivery->consumer->webhook_secret;
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $sig = $webhooks->sign($secret ?? '', $body);

        $delivery->attempts++;
        try {
            $response = Http::timeout((int) config('verification.webhook.timeout', 15))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Verification-Event' => $delivery->event,
                    'X-Verification-Event-Id' => $delivery->public_id,
                    'X-Verification-Timestamp' => (string) $sig['timestamp'],
                    'X-Verification-Signature' => $sig['signature'],
                ])
                ->withBody($body, 'application/json')
                ->post($delivery->url);

            $delivery->last_response_code = $response->status();

            if ($response->successful()) {
                $delivery->status = 'delivered';
                $delivery->delivered_at = now();
                $delivery->save();
                Verification::find($delivery->verification_id)
                    ?->recordEvent('webhook.delivered', ['event' => $delivery->event]);
                return;
            }

            $delivery->last_error = 'http_' . $response->status();
        } catch (\Throwable $e) {
            $delivery->last_response_code = null;
            $delivery->last_error = 'connection_error';
            Log::notice('webhook.delivery.failed', [
                'delivery' => $delivery->public_id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($delivery->attempts >= $delivery->max_attempts) {
            $delivery->status = 'dead_letter';
            $delivery->save();
            Verification::find($delivery->verification_id)
                ?->recordEvent('webhook.dead_letter', ['event' => $delivery->event, 'attempts' => $delivery->attempts]);
            return;
        }

        $backoff = min(3600, (int) config('verification.webhook.backoff_base', 30) * (2 ** ($delivery->attempts - 1)));
        $delivery->status = 'retrying';
        $delivery->next_attempt_at = now()->addSeconds($backoff);
        $delivery->save();

        self::dispatch($delivery->id)->delay($delivery->next_attempt_at)->onQueue('webhooks');
    }
}
