<?php

namespace Tests\Feature;

use App\Enums\VerificationStatus;
use App\Jobs\ProcessVerificationJob;
use App\Models\Verification;
use App\Services\Extraction\Contracts\ExtractionProvider;
use App\Services\Extraction\ExtractionResult;
use App\Services\Extraction\ProviderManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Full pipeline E2E with a scripted extraction provider — exercises the
 * real intake, quality, matching, decision, persistence and webhook code
 * paths without depending on a vendor API.
 */
class EndToEndVerificationTest extends TestCase
{
    private function bindFakeProvider(array $fields, ?string $type = 'national_id', ?string $country = 'ZW', float $conf = 0.9): void
    {
        $fake = new class($fields, $type, $country, $conf) implements ExtractionProvider {
            public function __construct(private array $f, private ?string $t, private ?string $c, private float $conf) {}
            public function name(): string { return 'fake'; }
            public function isAvailable(): bool { return true; }
            public function supports(string $m, string $p): bool { return true; }
            public function extract(string $p, string $m): ExtractionResult
            {
                return new ExtractionResult('fake', '1.0', $this->t, $this->c,
                    $this->f, array_fill_keys(array_keys($this->f), 0.9), $this->conf);
            }
        };
        $this->app->bind(ProviderManager::class, function () use ($fake) {
            $m = new ProviderManager();
            $m->register($fake);
            return $m;
        });
        config(['verification.extraction_providers' => ['fake']]);
    }

    private function subject(array $overrides = []): array
    {
        return array_merge([
            'external_id' => 'driver-1', 'first_name' => 'John',
            'surname' => 'Moyo', 'date_of_birth' => '1990-04-15', 'country' => 'ZW',
        ], $overrides);
    }

    private function createVerification(array $subject = [], ?string $callback = null): string
    {
        $payload = [
            'external_reference' => 'ref-' . uniqid(),
            'verification_policy' => 'generic_identity',
            'subject' => $this->subject($subject),
            'documents' => [['type' => 'national_id', 'file_base64' => $this->fakePngDocument()]],
        ];
        if ($callback) {
            $payload['callback'] = ['url' => $callback];
        }
        $res = $this->postJson('/api/v1/verifications', $payload, $this->apiHeaders());
        $res->assertStatus(202);
        return $res->json('data.verification_id');
    }

    private function runPipeline(string $id): Verification
    {
        $v = Verification::where('public_id', $id)->firstOrFail();
        (new ProcessVerificationJob($v->id))->handle(
            $this->app->make(ProviderManager::class),
            $this->app->make(\App\Services\QualityAnalyzer::class),
            $this->app->make(\App\Services\Matching\IdentityMatcher::class),
            $this->app->make(\App\Services\DecisionEngine::class),
            $this->app->make(\App\Services\WebhookService::class),
        );
        return $v->fresh();
    }

    public function test_matching_document_verifies(): void
    {
        Http::fake();
        $this->bindFakeProvider([
            'first_name' => 'John', 'surname' => 'Moyo', 'date_of_birth' => '1990-04-15',
        ]);
        $id = $this->createVerification();
        $v = $this->runPipeline($id);

        $this->assertSame(VerificationStatus::Verified, $v->statusEnum());
        $this->assertGreaterThanOrEqual(0.9, $v->confidence);
        $this->assertNotNull($v->completed_at);
        $this->assertTrue($v->fieldResults->where('field', 'first_name')->first()->match);
    }

    public function test_wrong_surname_fails(): void
    {
        $this->bindFakeProvider(['first_name' => 'John', 'surname' => 'Ncube', 'date_of_birth' => '1990-04-15']);
        $id = $this->createVerification();
        $v = $this->runPipeline($id);

        $this->assertSame(VerificationStatus::Failed, $v->statusEnum());
        $this->assertContains('SURNAME_MISMATCH', $v->reason_codes);
    }

    public function test_ambiguous_goes_to_review_then_admin_decides(): void
    {
        $this->bindFakeProvider(['first_name' => 'John', 'surname' => 'Moyu', 'date_of_birth' => '1990-04-15']);
        $id = $this->createVerification();
        $v = $this->runPipeline($id);

        $this->assertSame(VerificationStatus::ReviewRequired, $v->statusEnum());
        $this->assertContains('MANUAL_REVIEW_REQUIRED', $v->reason_codes);
    }

    public function test_expired_document_fails(): void
    {
        $this->bindFakeProvider([
            'first_name' => 'John', 'surname' => 'Moyo', 'date_of_birth' => '1990-04-15',
            'expiry_date' => '2020-01-01', 'document_number' => '63-1234567A89',
        ]);
        // use the schoolrun policy that requires non-expired
        $payload = [
            'external_reference' => 'ref-exp',
            'verification_policy' => 'schoolrun_driver_identity',
            'subject' => $this->subject(),
            'documents' => [['type' => 'national_id', 'file_base64' => $this->fakePngDocument()]],
        ];
        $res = $this->postJson('/api/v1/verifications', $payload, $this->apiHeaders())->assertStatus(202);
        $v = $this->runPipeline($res->json('data.verification_id'));

        $this->assertSame(VerificationStatus::Failed, $v->statusEnum());
        $this->assertContains('DOCUMENT_EXPIRED', $v->reason_codes);
    }

    public function test_webhook_delivered_on_completion(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->bindFakeProvider([
            'first_name' => 'John', 'surname' => 'Moyo', 'date_of_birth' => '1990-04-15',
        ]);
        $id = $this->createVerification([], 'https://consumer.example/hook');
        $v = $this->runPipeline($id);

        $this->assertSame('verified', $v->status);
        $delivery = $v->webhookDeliveries()->first();
        $this->assertNotNull($delivery);
        $this->assertSame('verification.completed', $delivery->event);
    }

    public function test_result_endpoint_hides_raw_pii(): void
    {
        $this->bindFakeProvider([
            'first_name' => 'John', 'surname' => 'Moyo', 'date_of_birth' => '1990-04-15',
            'document_number' => '63-1234567A89',
        ]);
        $id = $this->createVerification(['national_id_number' => '63-1234567A89']);
        $this->runPipeline($id);

        $res = $this->getJson("/api/v1/verifications/{$id}/result", $this->apiHeaders());
        $res->assertStatus(200);
        $body = $res->getContent();
        $this->assertStringNotContainsString('63-1234567A89', $body);
        $this->assertStringNotContainsString('raw_text', $body);
    }

    public function test_verification_history_is_immutable(): void
    {
        $this->bindFakeProvider([
            'first_name' => 'John', 'surname' => 'Moyo', 'date_of_birth' => '1990-04-15',
        ]);
        $id = $this->createVerification();
        $v = $this->runPipeline($id);

        $events = $v->events->pluck('event')->toArray();
        $this->assertContains('verification.created', $events);
        $this->assertContains('processing.started', $events);
        $this->assertContains('document.extracted', $events);
        $this->assertContains('identity.compared', $events);
        $this->assertContains('verification.completed', $events);
    }
}
