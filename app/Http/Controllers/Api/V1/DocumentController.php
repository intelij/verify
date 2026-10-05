<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessVerificationJob;
use App\Models\Verification;
use App\Services\DocumentIntake;
use App\Services\DocumentValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    /**
     * Attach a document to an awaiting_documents verification, then queue it.
     */
    public function store(Request $request, Verification $verification, DocumentIntake $intake): JsonResponse
    {
        if ($verification->consumer_id !== $request->attributes->get('consumer')->id) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Verification not found.']], 404);
        }
        if ($verification->status !== VerificationStatus::AwaitingDocuments->value
            && $verification->status !== VerificationStatus::Pending->value) {
            return response()->json(['error' => ['code' => 'not_accepting_documents',
                'message' => 'Verification is not accepting documents in its current state.']], 409);
        }

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:40'],
            'side' => ['nullable', 'string', Rule::in(['front', 'back'])],
            'file' => ['required_without:file_base64', 'file', 'max:12288',
                'mimes:jpg,jpeg,png,webp,heic,heif,pdf'],
            'file_base64' => ['required_without:file', 'string'],
            'complete' => ['nullable', 'boolean'],
        ]);

        try {
            if (isset($data['file_base64'])) {
                $b64 = $data['file_base64'];
                if (str_contains($b64, ',')) {
                    $b64 = substr($b64, strpos($b64, ',') + 1);
                }
                $bin = base64_decode($b64, true);
                if ($bin === false || strlen($bin) === 0) {
                    throw new DocumentValidationException('invalid_file', 'Document payload is not valid base64.');
                }
                $tmp = tempnam(sys_get_temp_dir(), 'vfy');
                file_put_contents($tmp, $bin);
                $doc = $intake->store($verification, $tmp, $data['type'] ?? null, null, $data['side'] ?? null);
                @unlink($tmp);
            } else {
                $doc = $intake->store($verification, $request->file('file'), $data['type'] ?? null, null, $data['side'] ?? null);
            }
        } catch (DocumentValidationException $e) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], 422);
        }

        $verification->recordEvent('document.uploaded', [
            'document' => $doc->public_id,
            'type' => $doc->declared_type,
        ], 'consumer', $request->attributes->get('consumer')->key_id);

        if (($data['complete'] ?? true) || $verification->documents->count() >= 1) {
            if ($verification->status === VerificationStatus::AwaitingDocuments->value) {
                $verification->transitionTo(VerificationStatus::Pending);
            }
            ProcessVerificationJob::dispatch($verification->id);
        }

        return response()->json([
            'document_id' => $doc->public_id,
            'status' => $doc->status,
            'verification_status' => $verification->fresh()->status,
        ], 202);
    }
}
