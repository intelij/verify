<?php

namespace Tests\Feature;

use App\Enums\VerificationStatus;
use App\Models\Verification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VerificationApiTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'external_reference' => 'schoolrun-driver-1',
            'verification_policy' => 'generic_identity',
            'subject' => [
                'external_id' => 'driver-1',
                'first_name' => 'John',
                'surname' => 'Moyo',
                'date_of_birth' => '1990-04-15',
                'country' => 'ZW',
            ],
        ], $overrides);
    }

    public function test_create_verification_returns_202_and_poll_url(): void
    {
        Queue::fake();
        $res = $this->postJson('/api/v1/verifications', $this->payload([
            'documents' => [['type' => 'national_id', 'file_base64' => $this->fakePngDocument()]],
        ]), $this->apiHeaders());

        $res->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['verification_id', 'poll_url']]);
    }

    public function test_create_without_documents_awaits_upload(): void
    {
        $res = $this->postJson('/api/v1/verifications', $this->payload(), $this->apiHeaders());
        $res->assertStatus(202)->assertJsonPath('data.status', 'awaiting_documents');
    }

    public function test_idempotency_key_returns_same_verification(): void
    {
        Queue::fake();
        $payload = $this->payload(['documents' => [['file_base64' => $this->fakePngDocument()]]]);
        $h = $this->apiHeaders(['Idempotency-Key' => 'key-123']);

        $first = $this->postJson('/api/v1/verifications', $payload, $h)->assertStatus(202);
        $id = $first->json('data.verification_id');

        $second = $this->postJson('/api/v1/verifications', $payload, $h);
        $second->assertStatus(200)->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('data.verification_id', $id);

        $this->assertSame(1, Verification::count());
    }

    public function test_invalid_policy_rejected(): void
    {
        $res = $this->postJson('/api/v1/verifications',
            $this->payload(['verification_policy' => 'nope']), $this->apiHeaders());
        $res->assertStatus(422)->assertJsonPath('error.code', 'invalid_policy');
    }

    public function test_policy_not_allowed_for_consumer(): void
    {
        $this->consumer->update(['allowed_policies' => ['some_other_policy']]);
        $res = $this->postJson('/api/v1/verifications', $this->payload(), $this->apiHeaders());
        $res->assertStatus(403)->assertJsonPath('error.code', 'policy_not_allowed');
    }

    public function test_unauthenticated_rejected(): void
    {
        $this->postJson('/api/v1/verifications', $this->payload())->assertStatus(401);
        $this->postJson('/api/v1/verifications', $this->payload(),
            ['Authorization' => 'Bearer garbage'])->assertStatus(401);
    }

    public function test_consumer_cannot_read_other_consumers_verification(): void
    {
        [$other, $otherToken] = \App\Models\Consumer::provision('Other', 'other');
        $v = Verification::create([
            'consumer_id' => $other->id,
            'verification_subject_id' => \App\Models\VerificationSubject::create([
                'consumer_id' => $other->id, 'external_id' => 'x',
                'first_name' => 'A', 'surname' => 'B',
            ])->id,
            'policy_code' => 'generic_identity',
            'external_reference' => 'ref',
            'status' => 'pending',
        ]);

        $this->getJson('/api/v1/verifications/' . $v->public_id, $this->apiHeaders())
            ->assertStatus(404); // no existence leak
    }

    public function test_cancel_pending_verification(): void
    {
        $v = Verification::create([
            'consumer_id' => $this->consumer->id,
            'verification_subject_id' => \App\Models\VerificationSubject::create([
                'consumer_id' => $this->consumer->id, 'external_id' => 'x',
                'first_name' => 'A', 'surname' => 'B',
            ])->id,
            'policy_code' => 'generic_identity',
            'external_reference' => 'ref',
            'status' => 'awaiting_documents',
        ]);

        $this->postJson("/api/v1/verifications/{$v->public_id}/cancel", [], $this->apiHeaders())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancel_terminal_rejected(): void
    {
        $v = Verification::create([
            'consumer_id' => $this->consumer->id,
            'verification_subject_id' => \App\Models\VerificationSubject::create([
                'consumer_id' => $this->consumer->id, 'external_id' => 'x',
                'first_name' => 'A', 'surname' => 'B',
            ])->id,
            'policy_code' => 'generic_identity',
            'external_reference' => 'ref',
            'status' => 'verified',
            'completed_at' => now(),
        ]);

        $this->postJson("/api/v1/verifications/{$v->public_id}/cancel", [], $this->apiHeaders())
            ->assertStatus(409);
    }

    public function test_document_upload_completes_awaiting_verification(): void
    {
        Queue::fake();
        $create = $this->postJson('/api/v1/verifications', $this->payload(), $this->apiHeaders());
        $id = $create->json('data.verification_id');

        $res = $this->postJson("/api/v1/verifications/{$id}/documents",
            ['file_base64' => $this->fakePngDocument(), 'type' => 'national_id'],
            $this->apiHeaders());

        $res->assertStatus(202)->assertJsonPath('verification_status', 'pending');
    }

    public function test_invalid_base64_rejected(): void
    {
        Queue::fake();
        $create = $this->postJson('/api/v1/verifications', $this->payload(), $this->apiHeaders());
        $id = $create->json('data.verification_id');

        $this->postJson("/api/v1/verifications/{$id}/documents",
            ['file_base64' => '!!!notbase64!!!'], $this->apiHeaders())
            ->assertStatus(422);
    }

    public function test_executable_file_rejected_by_magic_bytes(): void
    {
        Queue::fake();
        $elf = base64_encode("\x7FELF" . str_repeat("\x00", 100));
        $res = $this->postJson('/api/v1/verifications', $this->payload([
            'documents' => [['file_base64' => $elf]],
        ]), $this->apiHeaders());
        $res->assertStatus(422)->assertJsonPath('error.code', 'unsupported_file_type');
    }

    public function test_validation_errors(): void
    {
        $res = $this->postJson('/api/v1/verifications', [
            'verification_policy' => 'generic_identity',
        ], $this->apiHeaders());
        $res->assertStatus(422); // external_reference + subject required
    }
}
