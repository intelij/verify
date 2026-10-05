# SchoolRun Verification Service — Architecture

`verify.schoolrun.space` is a standalone Verification-as-a-Service platform.
SchoolRun is its first consumer; the domain has no SchoolRun-specific code.

## Pipeline

```
Consumer ──POST /api/v1/verifications──▶ API (auth, idempotency, validation)
        │                                    │ create verification + subject
        │                                    │ intake documents (magic-byte check)
        │                                    ▼
        │                              Queue: verifications
        │                                    │
        │                             ProcessVerificationJob
        │                                    │
        │        ┌───────────────┬───────────┼───────────────┐
        │        ▼               ▼           ▼               ▼
        │   QualityAnalyzer  ProviderManager  IdentityMatcher  DecisionEngine
        │   (blur/res/        (provider chain, (normalize +     (policy fusion
        │    contrast)         audited)        field scoring)    → status+reasons)
        │                                    │
        │                                    ▼
        │                      persist result + field results + events
        │                                    │
        │              ┌─────────────────────┴──────────────────┐
        │              ▼                                        ▼
        │     Queue: webhooks (HMAC-signed,             Polling API
        │     backoff, dead-letter)                     GET /verifications/{id}[/result]
        │              │
        ▼              ▼
   Consumer updates its own domain state (SchoolRun owns driver state)
```

## Separation of concerns

- The service **never** writes to SchoolRun's database. It emits a signed
  `verification.completed` webhook; SchoolRun's listener decides driver status.
- `pending → processing → verified|partially_verified|failed|review_required`
  plus `awaiting_documents`, `rejected`, `expired`, `error`, `cancelled`.
- Verification history is immutable: `verification_events` is append-only;
  re-verifications create new `verifications` rows.

## Components

| Component | File | Responsibility |
|---|---|---|
| `DocumentIntake` | `app/Services/DocumentIntake.php` | magic-byte MIME sniff, size limits, private storage, SHA-256 dedup |
| `QualityAnalyzer` | `app/Services/QualityAnalyzer.php` | resolution, Laplacian blur, contrast — actionable problems |
| `ProviderManager` | `app/Services/Extraction/ProviderManager.php` | ordered provider chain, per-attempt audit (`provider_attempts`) |
| `TesseractOcrProvider` | `app/Services/Extraction/TesseractOcrProvider.php` | local OCR; TSV word confidences; PDF via text layer or raster |
| `AwsTextractProvider` | `app/Services/Extraction/AwsTextractProvider.php` | adapter behind the same contract (disabled until configured) |
| `DocumentClassifier` | `app/Services/Extraction/DocumentClassifier.php` | heuristic doc-type + country detection; honest `unknown` |
| `FieldExtractor` | `app/Services/Extraction/FieldExtractor.php` | ICAO-9303 MRZ parser (check digits), label-proximity regexes, date + ID-number formats |
| `IdentityNormalizer` | `app/Services/Matching/IdentityNormalizer.php` | Unicode/case/punctuation/whitespace, comma-name reorder, initials |
| `IdentityMatcher` | `app/Services/Matching/IdentityMatcher.php` | per-field independent scoring; ambiguity → review, never auto-pass |
| `DecisionEngine` | `app/Services/DecisionEngine.php` | fuses quality/type/extraction/match/validity/authenticity via policy |
| `WebhookService` | `app/Services/WebhookService.php` | HMAC-SHA256 signed, idempotent deliveries |
| `ProcessVerificationJob` | `app/Jobs/ProcessVerificationJob.php` | async pipeline, retries, terminal-state idempotency |
| `DeliverWebhookJob` | `app/Jobs/DeliverWebhookJob.php` | exponential backoff → dead_letter |

## Data model

`consumers` (key_id + hashed secret, webhook secret, rate limit, allowed policies)
`verification_policies` (document types, required fields, thresholds)
`verification_subjects` (consumer-supplied expected identity)
`verifications` (public `ver_` ULID, status, reasons, confidence, idempotency key)
`verification_documents` (private storage, sha256, quality, detected type)
`document_extractions` (provider, fields — encrypted, confidences, signals)
`verification_field_results` (per-field match, masked expected value)
`verification_events` (immutable timeline)
`webhook_deliveries` (attempts, backoff schedule, dead-letter)
`verification_reviews` (human decision audit)
`provider_attempts` (provider observability)

## The four distinct claims

1. **OCR success** — `document_extractions.extraction_confidence`
2. **Identity match** — `verification_field_results` + `confidence`
3. **Authenticity** — `signals` (e.g. `mrz_check_digit_mismatch` → RISK_FLAG)
4. **External verification** — not claimed anywhere; no authoritative
   integration exists yet (`ExternalIdentityProvider` not implemented —
   see provider evaluation).

## Extensibility (verification graph)

Subjects are keyed by `(consumer_id, external_id)` and policies are data —
vehicle documents, insurance, background checks plug in as new
`allowed_document_types`/`required_fields` without schema changes. A future
"verification passport" can derive from the latest verified `verification`
per subject field set.

## Async model

- `QUEUE_CONNECTION=database` (dev) / `redis` (prod recommended)
- `verifications` queue: `ProcessVerificationJob`, `$tries` = config, `$timeout`
- `webhooks` queue: `DeliverWebhookJob`, backoff 30s→1h, max 8 attempts
- `verification:scan-stale` (5-min scheduler) re-dispatches stuck jobs and
  expires `awaiting_documents` uploads
- `verification:prune` (daily) applies document/OCR retention and expires
  verifications past `verified_until`

## Security summary

- Consumer auth: `Bearer {key_id}.{secret}`; secrets stored SHA-256 only.
- Webhooks signed `t={ts},v1={hmac-sha256(ts.body)}` + timestamp TTL.
- Cross-consumer reads return 404 (no existence leak).
- Private `documents` disk; admin document view is auth-gated + audited.
- Extracted fields stored via `encrypted:array` cast; raw OCR off by default.
- PII masking in logs/events via `PiiMasker`.
