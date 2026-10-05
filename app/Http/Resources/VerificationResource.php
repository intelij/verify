<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class VerificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'verification_id' => $this->public_id,
            'external_reference' => $this->external_reference,
            'policy' => $this->policy_code,
            'status' => $this->status,
            'reason_codes' => $this->reason_codes,
            'confidence' => $this->confidence,
            'poll_url' => '/api/v1/verifications/' . $this->public_id,
            'documents' => $this->whenLoaded('documents', fn () =>
                $this->documents->map(fn ($d) => [
                    'document_id' => $d->public_id,
                    'declared_type' => $d->declared_type,
                    'detected_type' => $d->detected_type,
                    'status' => $d->status,
                    'quality_score' => $d->quality_score,
                ])),
            'subject' => $this->whenLoaded('subject', fn () => [
                'type' => $this->subject->type,
                'external_id' => $this->subject->external_id,
                // name deliberately not echoed back beyond masked form
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'verified_until' => $this->verified_until?->toIso8601String(),
        ];
    }
}
