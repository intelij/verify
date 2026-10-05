# Verification Service — Security Audit

Audit of `schoolrun-verify` as built. Statuses are honest:
PASS / PARTIAL / NOT_IMPLEMENTED / RISK.

## Authentication & authorization

| Control | Status | Evidence |
|---|---|---|
| Consumer bearer auth (`key_id`.`secret`) | PASS | `ConsumerAuth` middleware; secrets stored SHA-256 only, shown once at provisioning |
| Inactive consumers rejected | PASS | `Consumer::isActive()` gate |
| Per-consumer rate limiting | PASS | `ThrottlePerConsumer`, per-consumer limit, `Retry-After` |
| Cross-consumer IDOR | PASS | All lookups scoped; foreign IDs → `404 not_found` (no existence leak). Test: `consumer_cannot_read_other_consumers_verification` |
| Admin dashboard auth | PASS | Session auth; all `/admin/*` behind `auth` middleware. Test: `admin_routes_require_auth` |
| Admin document view | PASS | `auth`-gated stream + `document.viewed` audit event |
| Stronger-than-API-key inbound auth (mTLS/OAuth) | PARTIAL | Bearer-token + per-consumer secret chosen for v1 simplicity; webhook signing covers the outbound direction. mTLS/OAuth2-client-credentials can be layered on later without API changes |

## Upload security

| Control | Status | Evidence |
|---|---|---|
| Magic-byte MIME detection | PASS | `DocumentIntake::sniffMime` ignores client headers; test `executable_file_rejected_by_magic_bytes` (ELF → 422) |
| Size limit | PASS | `VERIFICATION_MAX_UPLOAD_BYTES` (12MB default) |
| File-type allowlist | PASS | JPEG/PNG/WEBP/HEIC/PDF only |
| Malware scanning | PARTIAL | Signature/extension/MIME validation implemented; ClamAV integration NOT implemented — documented gap, `provider` slot exists for a scanning step |
| Storage isolation | PASS | Private `documents` disk, `serve=false`, no public URL; per-verification paths |

## Data protection / PII

| Control | Status | Evidence |
|---|---|---|
| Extracted fields encrypted at rest | PASS | `encrypted:array` cast on `document_extractions.fields` |
| Raw OCR retention | PASS | off by default (`STORE_RAW_OCR`), encrypted column when on, pruned by schedule |
| PII in logs | PASS | `PiiMasker` masks identifiers; no OCR text, no storage paths, no secrets logged. `expected_masked` stored on field results |
| PII in API responses | PASS | masked expected values only; test `result_endpoint_hides_raw_pii` |
| PII in webhooks | PASS | payload = ids + status + timestamps only |
| Retention | PASS | `verification:prune` — documents 90d, extracted 180d, results 730d (configurable) |

## Webhook security

| Control | Status | Evidence |
|---|---|---|
| HMAC-SHA256 signature `t,v1` | PASS | verified live + unit tests (tamper/wrong-secret/ expiry rejected) |
| Replay window | PASS | 300s timestamp TTL |
| Idempotent delivery | PASS | unique per (verification, event, status); consumers dedupe on event id |

## Pipeline integrity

| Control | Status | Evidence |
|---|---|---|
| Idempotent creation | PASS | `unique(consumer_id, idempotency_key)` + replay detection |
| Immutable history | PASS | `verification_events` append-only; reviews recorded w/ reviewer |
| Fuzzy-match auto-approval | PASS — prevented | ambiguity band → `review_required`, never verified |
| Provider privacy | PASS | documents only go to *configured* providers; Textract disabled until enabled+configured |

## Transport / infra

| Control | Status |
|---|---|
| HTTPS-only | DEPLOY — enforced at edge on `verify.schoolrun.space`; webhook URLs can be `http` in dev but production consumer config should require `https` (consider enforcing at policy level) |
| Secrets in VCS | PASS — `.env` gitignored, `.env.example` placeholders only |
| Audit logging of sensitive ops | PASS — document views, reviews, uploads recorded as events |

## Known gaps (documented, not hidden)

1. **No ClamAV/malware engine** — file validation is signature-based only.
2. **No document authenticity engine** — signals limited to MRZ check digits + heuristics; we never claim genuineness.
3. **Bearer-token-only inbound auth** — consider OAuth2 client-credentials or mTLS for higher assurance.
4. **`callback.url` accepts `http`** — production should reject non-TLS callbacks.
5. **Admin is single-role** — no reviewer/viewer/ops role split yet.
6. **No external-authoritative verification exists** — nothing should claim it.
