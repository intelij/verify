<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhookJob;
use App\Models\Verification;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    private WebhookService $webhooks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhooks = new WebhookService();
    }

    private function makeVerification(array $overrides = []): Verification
    {
        return Verification::create(array_merge([
            'consumer_id' => $this->consumer->id,
            'verification_subject_id' => \App\Models\VerificationSubject::create([
                'consumer_id' => $this->consumer->id, 'external_id' => 'x',
                'first_name' => 'A', 'surname' => 'B',
            ])->id,
            'policy_code' => 'generic_identity',
            'external_reference' => 'ref',
            'status' => 'verified',
            'callback_url' => 'https://consumer.example/webhook',
            'completed_at' => now(),
        ], $overrides));
    }

    public function test_signature_roundtrip(): void
    {
        $secret = 'whsec_test';
        $body = '{"event":"x"}';
        $sig = $this->webhooks->sign($secret, $body);
        $this->assertTrue($this->webhooks->verify($secret, $body, $sig['signature']));
        $this->assertFalse($this->webhooks->verify('wrong-secret', $body, $sig['signature']));
        $this->assertFalse($this->webhooks->verify($secret, $body . 'tampered', $sig['signature']));
    }

    public function test_expired_signature_rejected(): void
    {
        $secret = 'whsec_test';
        $body = 'x';
        $old = time() - 4000;
        $sig = 't=' . $old . ',v1=' . hash_hmac('sha256', $old . '.' . $body, $secret);
        $this->assertFalse($this->webhooks->verify($secret, $body, $sig));
    }

    public function test_dispatch_creates_signed_delivery(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $v = $this->makeVerification();

        $delivery = $this->webhooks->dispatch($v, 'verification.completed');
        $this->assertNotNull($delivery);
        $this->assertSame('pending', $delivery->status);
        $this->assertSame('verified', $delivery->payload['status']);

        (new DeliverWebhookJob($delivery->id))->handle($this->webhooks);

        Http::assertSent(function ($req) {
            return $req->hasHeader('X-Verification-Signature')
                && $req->hasHeader('X-Verification-Event-Id')
                && str_contains($req->header('X-Verification-Signature')[0], 't=');
        });

        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_failed_delivery_retries_then_dead_letters(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Http::fake(['*' => Http::response('down', 500)]);
        $v = $this->makeVerification();
        $delivery = $this->webhooks->dispatch($v, 'verification.completed');

        (new DeliverWebhookJob($delivery->id))->handle($this->webhooks);
        $d = $delivery->fresh();
        $this->assertSame('retrying', $d->status);
        $this->assertSame(1, $d->attempts);
        $this->assertSame(500, $d->last_response_code);
        $this->assertNotNull($d->next_attempt_at);

        // exhaust attempts
        $d->update(['attempts' => $d->max_attempts - 1, 'status' => 'retrying']);
        (new DeliverWebhookJob($d->id))->handle($this->webhooks);
        $this->assertSame('dead_letter', $d->fresh()->status);
    }

    public function test_no_callback_no_delivery(): void
    {
        $v = $this->makeVerification(['callback_url' => null]);
        $this->consumer->update(['config' => null]);
        $this->assertNull($this->webhooks->dispatch($v, 'verification.completed'));
    }

    public function test_dispatch_is_idempotent_per_status(): void
    {
        Http::fake();
        $v = $this->makeVerification();
        $a = $this->webhooks->dispatch($v, 'verification.completed');
        $b = $this->webhooks->dispatch($v, 'verification.completed');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, WebhookDelivery::count());
    }
}
