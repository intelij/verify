# Verification Service — E2E Report

Execution evidence for `schoolrun-verify`. Every claim below was run
against the live local app (`artisan serve` + `queue:work`), not assumed.

## Automated suite — 60 tests / 154 assertions: PASS

```
php artisan test   →   60 passed
```

Coverage by area:

| Area | Result |
|---|---|
| Identity normalization (case/ws/punct/diacritics/comma/initials/dates) | PASS |
| Field-level matching incl. independent name evaluation | PASS |
| Ambiguous → REVIEW_REQUIRED (never auto-approved) | PASS |
| Decision engine: quality/type/extraction/expiry/authenticity fusion | PASS |
| API create/poll/result/retry/cancel + documents upload | PASS |
| Idempotency-Key replay → same verification, no dup job | PASS |
| Consumer auth 401 / policy not allowed 403 / cross-consumer 404 | PASS |
| Webhook sign/verify, backoff → dead_letter, idempotent dispatch | PASS |
| Magic-byte file validation (ELF rejected, bad base64 rejected) | PASS |
| Admin review approve/reject + audit + auth gates | PASS |
| End-to-end pipeline with scripted provider (verify/fail/review/expire) | PASS |
| PII suppression in API output | PASS |

## Live pipeline runs (real Tesseract 5.5.2 OCR)

### Run 1 — matching ZW national ID → `review_required` (honest OCR ambiguity)

Synthetic GD-rendered ID card ("REPUBLIC OF ZIMBABWE / NATIONAL IDENTITY CARD",
SURNAME MOYO, FIRST NAME JOHN TENDAI, DOB 15 APR 1990, ID 63-1234567A89) POSTed
via `POST /api/v1/verifications` → `202 pending` → worker processed in ~3s:

- classified `national_id`, country `ZW` (keyword signals)
- extracted surname=MOYO, first=JOHN TENDAT (real OCR misread of TENDAI),
  dob=1990-04-15, document_number=63-1234567A89
- matched: first_name MATCH, surname MATCH, dob MATCH, id_number MATCH
- middle_names "tendai" vs "tendat" → `MANUAL_REVIEW_REQUIRED`
- **status `review_required`, confidence 0.813** — ambiguous OCR routed to a
  human instead of auto-approving: PASS

### Run 2 — surname mismatch → `failed`

Same card with SURNAME NCUBE vs registered Moyo:

- status `failed`, reasons `[FIRST_NAME_MATCH, SURNAME_MISMATCH,
  DATE_OF_BIRTH_MATCH]`, confidence 0.52 — PASS

### Webhook round-trip — PASS

Callback `http://127.0.0.1:8899/webhook` (live PHP listener):

- `X-Verification-Signature: t=1791188880,v1=05af…d0b5` delivered
- signature independently re-verified: valid=true, tampered-body=false,
  wrong-secret=false
- earlier delivery while listener was down retried on backoff and **delivered
  on a later attempt** — backoff works end-to-end

## Verified claims vs. NOT claimed

| Claim | Status |
|---|---|
| Async verification via queue | VERIFIED |
| JPEG/PNG intake + OCR | VERIFIED |
| PDF intake | VERIFIED (text layer via smalot/pdfparser; scanned-PDF needs ghostscript/poppler on host — partial) |
| HEIC intake | PARTIAL — accepted by validation; tesseract can't decode HEIC natively → would return no_text; convert step is a deploy note |
| Document type detection | VERIFIED (national_id detected + country) |
| MRZ parsing | VERIFIED (unit test on synthetic TD3) |
| Name matching with initials/middle names | VERIFIED |
| Review routing | VERIFIED live |
| Webhook signatures + retries | VERIFIED live |
| Document authenticity | NOT CLAIMED — signals only (MRZ check digits); no template/security-feature engine |
| External/government verification | NOT CLAIMED — no authoritative integration exists |
| Multi-page PDF OCR | PARTIAL — code path exists, needs poppler/ghostscript on the host (documented) |
| Liveness/face matching | NOT IMPLEMENTED — architecture leaves room; deliberately out of scope |

## Remaining work before production

- Provision real consumer credentials + webhook URL for SchoolRun
- Deploy workers + scheduler + TLS on `verify.schoolrun.space` (DEPLOYMENT.md)
- Optional phase-2 provider (Azure Doc Intelligence / dedicated IDV SDK)
- `callback.url` HTTPS-only enforcement in production
