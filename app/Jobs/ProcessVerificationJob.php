<?php

namespace App\Jobs;

use App\Enums\ReasonCode;
use App\Enums\VerificationStatus;
use App\Models\ProviderAttempt;
use App\Models\Verification;
use App\Models\VerificationPolicy;
use App\Services\DecisionEngine;
use App\Services\Extraction\Contracts\ProviderUnavailableException;
use App\Services\Extraction\ProviderManager;
use App\Services\Matching\IdentityMatcher;
use App\Services\QualityAnalyzer;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronous verification pipeline:
 *   quality -> extract -> match -> decide -> persist -> webhook
 *
 * Retries are safe: completed verifications short-circuit, extractions are
 * recreated per attempt, and the final transition is atomic.
 */
class ProcessVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries;

    public function __construct(public readonly int $verificationId)
    {
        $this->timeout = (int) config('verification.processing_timeout', 180);
        $this->tries = (int) config('verification.max_attempts', 3);
        $this->onQueue('verifications');
    }

    public function handle(
        ProviderManager $providers,
        QualityAnalyzer $quality,
        IdentityMatcher $matcher,
        DecisionEngine $decision,
        WebhookService $webhooks,
    ): void {
        $verification = Verification::with(['documents', 'subject', 'consumer'])->find($this->verificationId);
        if (! $verification || $verification->isTerminal()) {
            return; // idempotent: already finished or cancelled
        }

        // Re-dispatch stale awaiting_documents verifications is handled elsewhere.
        if ($verification->status === VerificationStatus::AwaitingDocuments->value) {
            return;
        }

        $verification->attempts++;
        $verification->started_processing_at ??= now();
        $verification->transitionTo(VerificationStatus::Processing);
        $verification->recordEvent('processing.started', ['attempt' => $verification->attempts]);

        $policy = VerificationPolicy::where('code', $verification->policy_code)->first();
        if (! $policy || ! $policy->enabled) {
            $this->finish($verification, VerificationStatus::Error, [ReasonCode::PROCESSING_ERROR], 'policy_missing', $webhooks);
            return;
        }

        if ($verification->documents->isEmpty()) {
            $this->finish($verification, VerificationStatus::Failed, [ReasonCode::REUPLOAD_REQUIRED], 'no_documents', $webhooks);
            return;
        }

        try {
            [$extractions, $qualities] = $this->processDocuments($verification, $providers, $quality);

            $expected = $verification->subject->expectedIdentity();
            $bestExtraction = collect($extractions)->sortByDesc('extractionConfidence')->first();
            $matchResult = $matcher->compare(
                $expected,
                $bestExtraction?->fields ?? [],
                $bestExtraction?->fieldConfidences ?? [],
            );
            $this->persistFieldResults($verification, $matchResult);
            $verification->recordEvent('identity.compared', [
                'score' => $matchResult['overall_score'],
            ]);

            $outcome = $decision->decide(
                $policy,
                $verification->expected_document_type,
                $matchResult,
                $extractions,
                $qualities,
            );

            $status = $outcome['status'];
            $verification->transitionTo($status, $outcome['reasons'], $outcome['confidence']);

            // Verification validity window from document expiry where known.
            if ($status === VerificationStatus::Verified || $status === VerificationStatus::PartiallyVerified) {
                $expiry = collect($extractions)->map(fn ($e) => $e->field('expiry_date'))
                    ->filter()->map(fn ($d) => strtotime($d))->filter()->min();
                if ($expiry) {
                    $verification->verified_until = \Carbon\Carbon::createFromTimestamp($expiry);
                    $verification->save();
                }
            }

            $verification->recordEvent('verification.completed', [
                'status' => $status->value,
                'confidence' => $outcome['confidence'],
                'reasons' => $outcome['reasons'],
            ]);
            $webhooks->dispatch($verification, 'verification.completed');
        } catch (ProviderUnavailableException $e) {
            $this->providerFailure($verification, $e, $webhooks);
        } catch (\Throwable $e) {
            Log::error('verification.pipeline.error', [
                'verification' => $verification->public_id,
                'error' => $e->getMessage(), // no PII
            ]);
            if ($verification->attempts >= $this->tries) {
                $this->finish($verification, VerificationStatus::Error, [ReasonCode::PROCESSING_ERROR], 'pipeline_error', $webhooks);
            } else {
                $verification->transitionTo(VerificationStatus::Pending, [ReasonCode::PROCESSING_ERROR]);
                $verification->recordEvent('processing.retry_scheduled', ['attempt' => $verification->attempts]);
                throw $e; // let the queue backoff/retry
            }
        }
    }

    private function processDocuments(Verification $v, ProviderManager $providers, QualityAnalyzer $quality): array
    {
        $extractions = [];
        $qualities = [];

        foreach ($v->documents as $doc) {
            $path = $doc->absolutePath();
            if ($path === null || ! is_file($path)) {
                $doc->update(['status' => 'missing']);
                continue;
            }

            $q = $quality->analyze($path, $doc->mime_type);
            $qualities[$doc->public_id] = $q;
            $doc->update([
                'quality' => ['metrics' => $q['metrics'], 'problems' => $q['problems']],
                'quality_score' => $q['score'],
            ]);
            $v->recordEvent('document.quality_assessed', [
                'document' => $doc->public_id,
                'score' => $q['score'],
            ]);

            $extraction = $providers->extract($path, $doc->mime_type, function ($provider, $status, $errorCode, $duration) use ($v, $doc) {
                ProviderAttempt::create([
                    'verification_id' => $v->id,
                    'verification_document_id' => $doc->id,
                    'provider' => $provider,
                    'operation' => 'extract',
                    'status' => $status,
                    'duration_ms' => $duration,
                    'error_code' => $errorCode,
                ]);
            });

            $doc->update([
                'detected_type' => $extraction->documentType,
                'detected_country' => $extraction->country,
                'page_count' => $extraction->pageCount,
                'status' => 'extracted',
            ]);

            $doc->extractions()->create([
                'provider' => $extraction->provider,
                'provider_version' => $extraction->providerVersion,
                'document_type' => $extraction->documentType,
                'country' => $extraction->country,
                'fields' => $extraction->fields,
                'field_confidences' => $extraction->fieldConfidences,
                'extraction_confidence' => $extraction->extractionConfidence,
                'signals' => $extraction->signals,
                'raw_text_encrypted' => config('verification.store_raw_ocr')
                    ? \Crypt::encryptString($extraction->rawText ?? '') : null,
                'duration_ms' => $extraction->durationMs,
            ]);

            $extractions[] = $extraction;
            $v->recordEvent('document.extracted', [
                'document' => $doc->public_id,
                'provider' => $extraction->provider,
                'type' => $extraction->documentType,
                'confidence' => $extraction->extractionConfidence,
            ]);
        }

        if ($extractions === []) {
            throw new ProviderUnavailableException('chain', 'no_documents_processable');
        }

        return [$extractions, $qualities];
    }

    private function persistFieldResults(Verification $v, array $matchResult): void
    {
        foreach ($matchResult['fields'] as $field => $r) {
            $v->fieldResults()->create([
                'field' => $field,
                'expected_masked' => $r->expectedMasked,
                'provided' => $r->provided,
                'match' => $r->match,
                'confidence' => $r->confidence,
                'reason_code' => $r->reasonCode,
            ]);
        }
    }

    private function finish(Verification $v, VerificationStatus $status, array $reasons, string $errorCode, WebhookService $webhooks): void
    {
        $v->last_error_code = $errorCode;
        $v->last_error_at = now();
        $v->transitionTo($status, $reasons);
        $v->recordEvent('verification.completed', ['status' => $status->value, 'reasons' => $reasons]);
        $webhooks->dispatch($v, 'verification.completed');
    }

    private function providerFailure(Verification $v, ProviderUnavailableException $e, WebhookService $webhooks): void
    {
        if ($v->attempts >= $this->tries) {
            $this->finish($v, VerificationStatus::Error, [ReasonCode::PROVIDER_UNAVAILABLE], 'provider_unavailable', $webhooks);
        } else {
            $v->transitionTo(VerificationStatus::Pending, [ReasonCode::PROVIDER_UNAVAILABLE]);
            $v->recordEvent('processing.retry_scheduled', ['reason' => 'provider_unavailable', 'attempt' => $v->attempts]);
            $this->release(30 * $v->attempts);
        }
    }

    public function failed(\Throwable $e): void
    {
        $v = Verification::find($this->verificationId);
        if ($v && ! $v->isTerminal()) {
            $v->last_error_code = 'job_failed';
            $v->last_error_at = now();
            $v->transitionTo(VerificationStatus::Error, [ReasonCode::PROCESSING_ERROR]);
            $v->recordEvent('verification.error', ['error_code' => 'job_failed']);
            app(WebhookService::class)->dispatch($v, 'verification.completed');
        }
    }
}
