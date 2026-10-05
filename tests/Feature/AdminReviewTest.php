<?php

namespace Tests\Feature;

use App\Enums\VerificationStatus;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminReviewTest extends TestCase
{
    private function reviewableVerification(): Verification
    {
        return Verification::create([
            'consumer_id' => $this->consumer->id,
            'verification_subject_id' => \App\Models\VerificationSubject::create([
                'consumer_id' => $this->consumer->id, 'external_id' => 'x',
                'first_name' => 'A', 'surname' => 'B',
            ])->id,
            'policy_code' => 'generic_identity',
            'external_reference' => 'ref',
            'status' => 'review_required',
            'reason_codes' => ['MANUAL_REVIEW_REQUIRED'],
            'callback_url' => 'https://consumer.example/hook',
        ]);
    }

    public function test_admin_approve_marks_verified_and_records_review(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $v = $this->reviewableVerification();

        $this->actingAs($admin)
            ->post("/admin/verifications/{$v->public_id}/review", ['decision' => 'approve', 'notes' => 'looks fine'])
            ->assertRedirect();

        $v->refresh();
        $this->assertSame(VerificationStatus::Verified, $v->statusEnum());
        $this->assertSame(1, $v->reviews()->count());
        $this->assertSame('approve', $v->reviews->first()->decision);
        $this->assertContains('MANUAL_REVIEW_APPROVED', $v->reason_codes);
        $this->assertTrue($v->events->contains('event', 'review.completed'));
    }

    public function test_admin_reject_marks_rejected(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $v = $this->reviewableVerification();

        $this->actingAs($admin)
            ->post("/admin/verifications/{$v->public_id}/review", ['decision' => 'reject'])
            ->assertRedirect();

        $this->assertSame(VerificationStatus::Rejected, $v->fresh()->statusEnum());
    }

    public function test_review_not_allowed_on_non_reviewable_status(): void
    {
        $admin = User::factory()->create();
        $v = $this->reviewableVerification();
        $v->update(['status' => 'verified']);

        $this->actingAs($admin)
            ->post("/admin/verifications/{$v->public_id}/review", ['decision' => 'approve'])
            ->assertSessionHasErrors();
    }

    public function test_admin_routes_require_auth(): void
    {
        $this->get('/admin')->assertRedirect();
        $this->get('/admin/verifications')->assertRedirect();
        $this->get('/admin/reviews')->assertRedirect();
    }

    public function test_document_view_requires_auth(): void
    {
        $v = $this->reviewableVerification();
        $doc = $v->documents()->create([
            'storage_disk' => 'documents', 'storage_path' => 'x/y.png',
            'mime_type' => 'image/png', 'size_bytes' => 1, 'sha256' => 'a',
        ]);
        $this->get("/admin/documents/{$doc->public_id}/view")->assertRedirect();
    }
}
