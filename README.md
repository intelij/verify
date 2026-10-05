# SchoolRun Verification Service

Standalone Verification-as-a-Service platform (`verify.schoolrun.space`).
Consumers submit identity documents; the async pipeline extracts, matches,
decides, and notifies via signed webhooks. SchoolRun is the first consumer.

## Stack

Laravel 12 · PHP 8.3 · database/Redis queues · private local or S3 document
storage · local Tesseract OCR (provider-abstracted).

## Quick start

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
apt/brew install tesseract         # + ghostscript, poppler-utils for scanned PDFs
php artisan serve
php artisan queue:work --queue=verifications,webhooks

# provision a consumer (prints bearer token + webhook secret once)
php artisan consumer:create SchoolRun schoolrun \
    --policies=schoolrun_driver_identity --policies=generic_identity
```

```bash
curl -X POST http://localhost:8000/api/v1/verifications \
  -H "Authorization: Bearer {key_id}.{secret}" \
  -H "Idempotency-Key: $(uuidgen)" -H 'Content-Type: application/json' \
  -d '{"external_reference":"driver-1","verification_policy":"generic_identity",
       "subject":{"external_id":"driver-1","first_name":"John","surname":"Moyo","country":"ZW"},
       "documents":[{"file_base64":"..."}]}'
```

Admin dashboard: `/admin` (seeded `admin@schoolrun.space` — rotate immediately).

## Docs

- `docs/VERIFICATION_SERVICE_ARCHITECTURE.md` — components + data model
- `docs/VERIFICATION_PROVIDER_EVALUATION.md` — OCR/IDV provider comparison
- `docs/VERIFICATION_SECURITY_AUDIT.md` — controls + honest gaps
- `docs/VERIFICATION_E2E_REPORT.md` — what was actually executed
- `docs/SCHOOLRUN_INTEGRATION.md` — consumer integration guide
- `docs/DEPLOYMENT.md` — production setup
- `docs/openapi.yaml` — OpenAPI 3 contract

## Tests

```bash
php artisan test    # 60 tests
```
