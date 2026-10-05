# SchoolRun Integration Guide

How SchoolRun consumes `verify.schoolrun.space`. SchoolRun remains
authoritative for driver state — it *receives* verification results, it
never writes into the verification service.

## 1. Credentials

Provisioned out-of-band via `php artisan consumer:create SchoolRun schoolrun`:

- **Bearer token**: `Authorization: Bearer {key_id}.{secret}` on every request.
- **Webhook secret**: `whsec_…` for verifying inbound signatures.

Store both in Laravel's `services.verify` config via env — never in code.

## 2. Create a verification

```http
POST /api/v1/verifications
Authorization: Bearer {token}
Idempotency-Key: driver-12345-national-id-{uuid}
Content-Type: application/json

{
  "external_reference": "driver-12345",
  "verification_policy": "schoolrun_driver_identity",
  "expected_document_type": "national_id",
  "subject": {
    "external_id": "driver-12345",
    "first_name": "John",
    "middle_names": "Tendai",
    "surname": "Moyo",
    "date_of_birth": "1990-04-15",
    "country": "ZW",
    "national_id_number": "63-1234567A89"
  },
  "documents": [
    { "type": "national_id", "side": "front", "file_base64": "..." }
  ],
  "callback": { "url": "https://api.schoolrun.space/api/verify/webhook" }
}
```

`202 Accepted` → `{ "verification_id": "ver_…", "status": "pending", "poll_url": "/api/v1/verifications/ver_…" }`.

Two document flows:
- **inline** `documents[].file_base64` (shown above) — simplest.
- **two-step** — omit `documents`; status becomes `awaiting_documents`;
  `POST /api/v1/verifications/{id}/documents` (multipart `file` or
  `file_base64`), then processing starts automatically.

**Idempotency**: always send `Idempotency-Key`. A replayed key returns the
original verification with `200` + `X-Idempotent-Replay: true`, never a
duplicate job.

## 3. Receive the webhook

```http
POST https://api.schoolrun.space/api/verify/webhook
X-Verification-Event: verification.completed
X-Verification-Event-Id: evt_…
X-Verification-Timestamp: 1759660000
X-Verification-Signature: t=1759660000,v1={hmac}

{
  "event": "verification.completed",
  "verification_id": "ver_…",
  "external_reference": "driver-12345",
  "status": "verified",
  "occurred_at": "2026-10-05T08:20:31+00:00"
}
```

Verify in Laravel (sketch — implement on the SchoolRun side):

```php
$sig = $request->header('X-Verification-Signature'); // t=…,v1=…
$ok  = app(VerifyWebhookVerifier::class)->check(
    $sig, $request->getContent(), config('services.verify.webhook_secret'));
abort_unless($ok, 401);
// then GET /api/v1/verifications/{id}/result and update driver state
```

Rules: reject `|now - t| > 300s`; compare `hash_hmac('sha256', "$t.$body", $secret)`
with `v1` using `hash_equals`; dedupe on `X-Verification-Event-Id`.

Deliveries retry with exponential backoff (30s → 1h, 8 attempts) then
dead-letter — **always implement polling fallback**.

## 4. Poll the result

```http
GET /api/v1/verifications/ver_…/result   →  rich result (fields, reasons, confidences)
GET /api/v1/verifications/ver_…          →  status + document summaries
```

Result shape:

```json
{
  "data": {
    "verification_id": "ver_…",
    "status": "review_required",
    "result": {
      "overall_confidence": 0.813,
      "document_type": "national_id",
      "document_country": "ZW",
      "identity_match": true,
      "fields": {
        "first_name": {"match": true, "confidence": 0.75, "reason": "FIRST_NAME_MATCH"},
        "surname":    {"match": true, "confidence": 0.75, "reason": "SURNAME_MATCH"},
        "date_of_birth": {"match": true, "confidence": 0.8, "reason": "DATE_OF_BIRTH_MATCH"}
      },
      "reasons": ["MANUAL_REVIEW_REQUIRED"],
      "authenticity_signals": ["id_format:ZW"]
    }
  }
}
```

## 5. Map statuses to SchoolRun driver state

| Service status | Suggested SchoolRun driver state |
|---|---|
| pending / processing / awaiting_documents | `verification_pending` |
| verified | `verified` |
| partially_verified | `verified_with_flags` (ops look) |
| review_required | `under_review` — human is looking, not a fail |
| failed | `verification_failed` + surface `reasons` |
| rejected | `verification_rejected` |
| expired / error | `reverification_required` |

Stable reason codes (contract): `*_MATCH`, `*_MISMATCH`, `FIELD_NOT_EXTRACTED`,
`LOW_EXTRACTION_CONFIDENCE`, `DOCUMENT_UNREADABLE`, `REUPLOAD_REQUIRED`,
`DOCUMENT_TYPE_MISMATCH`, `DOCUMENT_TYPE_NOT_ALLOWED`, `DOCUMENT_EXPIRED`,
`DOCUMENT_AUTHENTICITY_FAILED`, `RISK_FLAG`, `MANUAL_REVIEW_REQUIRED`,
`PROVIDER_UNAVAILABLE`, `PROCESSING_ERROR`, `CANCELLED_BY_CONSUMER`.

## 6. Reconciliation

Daily job on SchoolRun:

1. find drivers stuck in `verification_pending` older than N minutes
2. `GET /api/v1/verifications/{id}` for each and apply the status map
3. alert ops on `error`/`dead_letter` patterns

## 7. Other operations

```http
POST /api/v1/verifications/{id}/retry    # only failed|error → re-queue
POST /api/v1/verifications/{id}/cancel   # only non-terminal
```

New document for a decided driver = a **new** verification (immutable history).

## Errors

`401 unauthenticated` · `403 policy_not_allowed` · `404 not_found` (also used
for cross-consumer access — no existence leak) · `409 not_retryable |
already_terminal | not_accepting_documents` · `422 validation |
invalid_policy | unsupported_file_type | file_too_large | invalid_file` ·
`429 rate_limited` (`Retry-After` header).
