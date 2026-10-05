<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVerificationRequest;
use App\Http\Resources\VerificationResource;
use App\Http\Resources\VerificationResultResource;
use App\Jobs\ProcessVerificationJob;
use App\Models\Verification;
use App\Models\VerificationPolicy;
use App\Models\VerificationSubject;
use App\Services\DocumentIntake;
use App\Services\DocumentValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function store(StoreVerificationRequest $request, DocumentIntake $intake): JsonResponse
    {
        $consumer = $request->attributes->get('consumer');
        $data = $request->validated();

        // Policy must exist, be enabled, and be allowed for this consumer.
        $policy = VerificationPolicy::where('code', $data['verification_policy'])->first();
        if (! $policy || ! $policy->enabled) {
            return $this->error('invalid_policy', 'Unknown or disabled verification policy.', 422);
        }
        if ($consumer->allowed_policies !== null && ! in_array($policy->code, $consumer->allowed_policies, true)) {
            return $this->error('policy_not_allowed', 'This policy is not permitted for your consumer account.', 403);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        // Idempotent create: same key + consumer returns the original record.
        if ($idempotencyKey) {
            $existing = Verification::where('consumer_id', $consumer->id)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return (new VerificationResource($existing->load(['documents', 'subject'])))
                    ->response()->setStatusCode(200)->header('X-Idempotent-Replay', 'true');
            }
        }

        $subject = VerificationSubject::create([
            'consumer_id' => $consumer->id,
            'type' => $data['subject']['type'] ?? 'person',
            'external_id' => $data['subject']['external_id'],
            'first_name' => $data['subject']['first_name'],
            'middle_names' => $data['subject']['middle_names'] ?? null,
            'surname' => $data['subject']['surname'],
            'date_of_birth' => $data['subject']['date_of_birth'] ?? null,
            'country' => isset($data['subject']['country']) ? strtoupper($data['subject']['country']) : null,
            'national_id_number' => $data['subject']['national_id_number'] ?? null,
            'passport_number' => $data['subject']['passport_number'] ?? null,
            'driver_license_number' => $data['subject']['driver_license_number'] ?? null,
            'gender' => $data['subject']['gender'] ?? null,
        ]);

        $hasInlineDocs = ! empty($data['documents']);
        $verification = new Verification([
            'consumer_id' => $consumer->id,
            'verification_subject_id' => $subject->id,
            'policy_code' => $policy->code,
            'external_reference' => $data['external_reference'],
            'idempotency_key' => $idempotencyKey,
            'status' => $hasInlineDocs ? VerificationStatus::Pending->value : VerificationStatus::AwaitingDocuments->value,
            'expected_document_type' => $data['expected_document_type'] ?? ($data['documents'][0]['type'] ?? null),
            'callback_url' => $data['callback']['url'] ?? null,
        ]);
        if (! $hasInlineDocs) {
            $verification->documents_due_at = now()->addMinutes((int) config('verification.documents_due_minutes', 60));
        }

        try {
            $verification->save();
        } catch (QueryException $e) {
            // Unique(consumer, idempotency_key) race — return the winner.
            if ($idempotencyKey && str_contains($e->getMessage(), 'unique')) {
                $existing = Verification::where('consumer_id', $consumer->id)
                    ->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return (new VerificationResource($existing->load(['documents', 'subject'])))
                        ->response()->setStatusCode(200)->header('X-Idempotent-Replay', 'true');
                }
            }
            throw $e;
        }

        $verification->recordEvent('verification.created', [
            'policy' => $policy->code,
            'external_reference' => $data['external_reference'],
        ], 'consumer', $consumer->key_id);

        if ($hasInlineDocs) {
            try {
                foreach ($data['documents'] as $docData) {
                    $tmp = $this->decodeBase64ToTemp($docData['file_base64']);
                    $intake->store($verification, $tmp, $docData['type'] ?? null, $docData['filename'] ?? null, $docData['side'] ?? null);
                    @unlink($tmp);
                    $verification->recordEvent('document.uploaded', [], 'consumer', $consumer->key_id);
                }
            } catch (DocumentValidationException $e) {
                return $this->error($e->errorCode, $e->getMessage(), 422);
            }
            ProcessVerificationJob::dispatch($verification->id);
        }

        return (new VerificationResource($verification->load(['documents', 'subject'])))
            ->response()->setStatusCode(202);
    }

    public function show(Request $request, Verification $verification): VerificationResource|JsonResponse
    {
        if ($resp = $this->authorizeConsumer($request, $verification)) {
            return $resp;
        }
        return new VerificationResource($verification->load(['documents', 'subject']));
    }

    public function result(Request $request, Verification $verification): VerificationResultResource|JsonResponse
    {
        if ($resp = $this->authorizeConsumer($request, $verification)) {
            return $resp;
        }
        return new VerificationResultResource($verification->load(['documents.extractions', 'fieldResults']));
    }

    public function retry(Request $request, Verification $verification): JsonResponse
    {
        if ($resp = $this->authorizeConsumer($request, $verification)) {
            return $resp;
        }
        if (! in_array($verification->status, [
            VerificationStatus::Failed->value, VerificationStatus::Error->value,
        ], true)) {
            return $this->error('not_retryable', 'Only failed or errored verifications can be retried.', 409);
        }

        $verification->attempts = 0;
        $verification->transitionTo(VerificationStatus::Pending);
        $verification->recordEvent('verification.retry_requested', [], 'consumer', $request->attributes->get('consumer')->key_id);
        ProcessVerificationJob::dispatch($verification->id);

        return (new VerificationResource($verification))->response()->setStatusCode(202);
    }

    public function cancel(Request $request, Verification $verification): JsonResponse
    {
        if ($resp = $this->authorizeConsumer($request, $verification)) {
            return $resp;
        }
        if ($verification->isTerminal()) {
            return $this->error('already_terminal', 'Verification is already complete and cannot be cancelled.', 409);
        }

        $verification->transitionTo(VerificationStatus::Cancelled, ['CANCELLED_BY_CONSUMER']);
        $verification->recordEvent('verification.cancelled', [], 'consumer', $request->attributes->get('consumer')->key_id);

        return (new VerificationResource($verification))->response();
    }

    private function authorizeConsumer(Request $request, Verification $verification): ?JsonResponse
    {
        if ($verification->consumer_id !== $request->attributes->get('consumer')->id) {
            return $this->error('not_found', 'Verification not found.', 404); // no existence leak across consumers
        }
        return null;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function decodeBase64ToTemp(string $b64): string
    {
        // tolerate data-url prefix
        if (str_contains($b64, ',')) {
            $b64 = substr($b64, strpos($b64, ',') + 1);
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) === 0) {
            throw new DocumentValidationException('invalid_file', 'Document payload is not valid base64.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'vfy');
        file_put_contents($tmp, $bin);
        return $tmp;
    }
}
