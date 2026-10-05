# Deployment — verify.schoolrun.space

## Infrastructure

```
verify.schoolrun.space (TLS) ─▶ nginx ─▶ php-fpm (Laravel app)
                                      ├─ php artisan queue:work --queue=verifications,webhooks (supervisor)
                                      └─ php artisan schedule:work (or cron: * * * * * artisan schedule:run)
MySQL ─▶ private object storage (local disk or S3-compatible bucket)
OCR: local `tesseract` binary (no external calls by default)
```

## Forge / server setup

1. **New site** `verify.schoolrun.space`, PHP 8.3, isolated from the SchoolRun
   app (separate directory + separate DB).
2. **DNS**: `verify.schoolrun.space` A/AAAA → server; issue Let's Encrypt cert.
3. **System packages**:
   ```bash
   apt install tesseract-ocr ghostscript poppler-utils   # OCR + PDF rasterization
   ```
4. **Clone + build**:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force          # policies + first admin user
   php artisan storage:link
   mkdir -p storage/app/documents && chmod 750 storage/app/documents
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
5. **Provision the SchoolRun consumer** (run once, store output in secrets):
   ```bash
   php artisan consumer:create SchoolRun schoolrun \
       --policies=schoolrun_driver_identity \
       --policies=schoolrun_driver_license \
       --policies=generic_identity
   ```
   Put the bearer token + webhook secret in SchoolRun's `.env` as
   `VERIFY_TOKEN` / `VERIFY_WEBHOOK_SECRET`.
6. **Workers** (Forge daemon or supervisor):
   ```
   php artisan queue:work --queue=verifications,webhooks --sleep=1 --tries=3 --timeout=240
   ```
7. **Scheduler**: `* * * * * cd /home/forge/verify.schoolrun.space && php artisan schedule:run`

## Production .env (see .env.example)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://verify.schoolrun.space
DB_CONNECTION=mysql (host/db/user/pass)
QUEUE_CONNECTION=redis            # recommended; database works
SESSION_DRIVER=database
FILESYSTEM_DISK=local
VERIFICATION_STORAGE_DISK=documents   # or an S3 disk for object storage
TESSERACT_BINARY=/usr/bin/tesseract
ADMIN_EMAIL=...
ADMIN_PASSWORD=...                # rotate after first login
```

## Security checklist

- [ ] `APP_DEBUG=false`
- [ ] `documents` disk is NOT served publicly (admin route is the only access)
- [ ] TLS enforced; nginx redirects 80→443
- [ ] Admin credentials rotated from seed values
- [ ] Consumer secrets + webhook secret in Forge secrets, not repo
- [ ] `STORE_RAW_OCR=false` unless required + documented
- [ ] Queue worker running under a non-root user
- [ ] Backups exclude `storage/app/documents` or encrypt them

## Health

- `GET /up` — liveness
- `GET /api/v1/verifications` without token → `401` confirms auth middleware
- Worker: `supervisorctl status` shows `queue:work` RUNNING
