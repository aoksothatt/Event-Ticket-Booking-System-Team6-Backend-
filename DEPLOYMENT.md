# Render Docker Deployment

This guide deploys the Laravel API from `Backend/` as a Docker Web Service. The Vue SPA remains a separate frontend and calls this API over HTTPS.

## Container design

- PHP 8.3-FPM runs Laravel; Nginx serves `public/` and forwards PHP requests to FPM; Supervisor keeps both processes running and sends their logs to stdout/stderr.
- A Node build stage compiles the Laravel Vite assets. Composer installs production-only PHP dependencies in the PHP image.
- The image does not copy `.env` files, local `vendor/`, or uploaded files. It does not run migrations or seeders.
- Render's `PORT` is substituted into Nginx at container startup; the local default is `10000`. Laravel trusts Render's forwarded host and scheme headers.
- Laravel's built-in `/up` endpoint is the health check. It does not require authentication or a database query.

## Build and test locally

Run these commands from the repository root with Docker Desktop or another Docker Engine running:

```powershell
docker build --pull -t event-ticket-booking-api:local ./Backend
docker run --rm --entrypoint php event-ticket-booking-api:local -v
docker run --rm --entrypoint php event-ticket-booking-api:local -m
docker run --rm --entrypoint composer event-ticket-booking-api:local show --no-dev
docker run --rm --env-file Backend/.env --env PORT=10000 -p 10000:10000 event-ticket-booking-api:local
```

In another terminal, verify startup and health:

```powershell
curl.exe --fail http://localhost:10000/up
```

The `--env-file` supplies local runtime configuration only; the values are not added to the image. Keep `Backend/.env` out of source control. Stop the container with Ctrl+C.

The image entrypoint requires `APP_KEY`, `JWT_SECRET`, `DB_URL`, and `APP_URL`, requires `APP_DEBUG=false` in production, validates `PORT`, and then creates Laravel's config, route, and view caches from the runtime environment.

## Render setup

The root `render.yaml` defines the backend Blueprint. Push the repository to GitHub, then in Render choose **New → Blueprint**, connect the repository, and review the service before syncing it. The service settings are:

- Type: Web Service
- Runtime: Docker
- Region: Ohio (near the Neon project in `us-east-2`)
- Root Directory: `Backend`
- Dockerfile Path: `./Dockerfile`
- Docker Context: `.` (the `Backend/` directory)
- Health Check Path: `/up`
- HTTP listener: Render-provided `PORT`; do not hard-code a port in the Dashboard

The Blueprint selects Render's paid Starter plan and attaches a 1 GB persistent disk at `/var/www/html/storage/app/public`. The app currently saves event images, avatars, and logos to Laravel's `public` disk there. The persistent disk keeps those uploads across restarts and deploys; Render disks require a paid service and constrain the service to a single instance, with zero-downtime deploys unavailable. For horizontal scaling, use an external object store such as Cloudinary or S3-compatible storage instead. The Cloudinary PHP SDK is present in Composer, but current upload code does not use Cloudinary.

The Blueprint uses `sync: false` placeholders for secret or deployment-specific values. Set them in Render's Environment page and never add real values to `render.yaml`:

| Variable | Value to configure |
| --- | --- |
| `APP_KEY` | Existing production Laravel key if preserving encrypted data, otherwise a securely generated key |
| `APP_URL` | Public HTTPS URL of the Render API service |
| `JWT_SECRET` | Existing production JWT signing secret if preserving token compatibility; otherwise generate and store a new secret |
| `DB_URL` | Neon pooled connection URL (`DATABASE_URL`, hostname includes `-pooler`) for web requests |
| `DATABASE_URL_UNPOOLED` | Neon direct/unpooled connection URL for backups and one-off migrations |
| `FRONTEND_URL` | Public HTTPS URL of the Vue site |
| `CORS_ALLOWED_ORIGINS` | Allowed browser origin(s), comma-separated, without trailing slashes |
| `MAIL_*` | SMTP provider settings; required for email OTP and ticket mail delivery |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | Google OAuth settings if Google login is enabled; callback URI is `${APP_URL}/auth/google/callback` |
| `BAKONG_*` | Merchant/API credentials and callback settings if Bakong payments are enabled; `BAKONG_CALLBACK_URL` should be `${APP_URL}/api/payments/webhook` |

Set `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `DB_CONNECTION=pgsql`, and `DB_SSLMODE=require`. Render supplies `PORT` automatically. Keep `APP_KEY`, `JWT_SECRET`, SMTP passwords, OAuth secrets, Bakong credentials, and both Neon URLs in Render secrets.

### Vue frontend

Create a separate Render Static Site from the same repository:

- Root Directory: `Frontend`
- Build Command: `npm ci && npm run build`
- Publish Directory: `dist`
- `VITE_BACKEND_URL`: the API's public HTTPS URL
- `VITE_API_BASE`: the API URL followed by `/api`
- `VITE_APP_URL`: the public Vue site URL
- `VITE_GOOGLE_REDIRECT_URL`: `${VITE_BACKEND_URL}/auth/google` when Google login is used

Vite variables are compiled into browser assets. Never put database URLs or other server secrets in any `VITE_*` variable. Set backend `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS` to the same Vue origin.

## Production database and migrations

The application uses the pooled Neon URL at runtime. For schema migrations and backups, use `DATABASE_URL_UNPOOLED` (direct connection), not the pooled URL.

1. Take and verify a Neon backup or point-in-time restore point before schema changes. A custom-format dump can be made with a PostgreSQL client at least as new as the server:

   ```sh
   pg_dump --format=custom --no-owner --file=before-migration.dump "$DATABASE_URL_UNPOOLED"
   ```

2. Review the migration history over the direct connection in Render Shell:

   ```sh
   export DB_URL="$DATABASE_URL_UNPOOLED"
   php artisan migrate:status
   ```

3. Only after explicit approval, apply pending migrations:

   ```sh
   php artisan migrate --force
   ```

The Docker entrypoint intentionally does not run migrations or seed production data. Never use `migrate:fresh`, `db:wipe`, or `migrate:reset` against production.

## Rollout checks

After deploy, check Render logs for Nginx, PHP-FPM, and Laravel startup errors, then request `https://<api-host>/up`. After the approved migrations complete, check a public API route such as `/api/events`, then test login and JWT-protected routes through the Vue app. A healthy `/up` response confirms the web process is ready; it does not confirm database schema readiness.

Local development is unchanged: use `composer install`, `php artisan serve`, and the existing `npm run dev` commands. Docker is only for the production Render service.
