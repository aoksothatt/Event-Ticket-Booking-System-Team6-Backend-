# Bakong Payment Module — Installation Guide

## Prerequisites

- PHP 8.2+ with `pdo_pgsql` and `gd` extensions
- Composer
- PostgreSQL (dev: `Event&Booking`)
- A Bakong merchant account + credentials (see [Bakong Open API docs](https://developer.bakongkh.com))

## 1. Install

```bash
git checkout payment-bakong
composer install
```

This installs the new dependency `endroid/qr-code` (ticket QR images).

## 2. Configure the environment

Copy the new variables into your `.env` (already added to `.env.example`):

```dotenv
# Bakong Open API
BAKONG_BASE_URL=https://api-bakong.nbc.gov.kh/v1
BAKONG_CLIENT_ID=
BAKONG_CLIENT_SECRET=
BAKONG_API_KEY=
BAKONG_TOKEN=            # pre-provisioned JWT (used directly if no client_id/secret)
BAKONG_ACCOUNT=your-bakong-account-id
BAKONG_MERCHANT=YourStoreName
BAKONG_CURRENCY=USD      # or KHR
BAKONG_TIMEOUT=30
BAKONG_CALLBACK_URL=https://your-domain.com/api/payments/webhook
BAKONG_WEBHOOK_SECRET=generate-a-long-random-secret
BAKONG_QR_EXPIRATION_MINUTES=15
```

> Credentials are only read from the environment — never hardcode them.
> If you do not have OAuth `client_id`/`client_secret`, provide a Bakong-issued
> `BAKONG_TOKEN` and the service uses it directly.

## 3. Migrate

```bash
php artisan migrate
php artisan db:seed    # optional baseline data
```

New migrations (append-only, safe on existing installs):

| Migration | Effect |
|-----------|--------|
| `2026_09_09_000001_...` | Adds Bakong columns to `payments` |
| `2026_09_09_000002_...` | Adds `discount`, `service_fee` to `Booking` |
| `2026_09_09_000003_...` | Adds `status` to `payments`; makes `payment_method` nullable |
| `2026_09_09_000004_...` | Adds `ticket_number`, `qr_code`, `issued_at`, `checked_in_at` to `tickets` |

## 4. Storage symlink

Ticket QR images are stored on the `public` disk:

```bash
php artisan storage:link
```

## 5. Configure the webhook in the Bakong developer portal

Point Bakong to `BAKONG_CALLBACK_URL` with header `X-Bakong-Signature` = `BAKONG_WEBHOOK_SECRET`.

## 6. Run tests

Tests use a dedicated PostgreSQL database (`Event&Booking_test`).

```bash
php artisan test
```

> If the sqlite driver is missing on your machine (this project uses PostgreSQL),
> the `phpunit.xml` already points at `Event&Booking_test`. Create it once:
> `createdb "Event&Booking_test"` (or via your DB GUI).

## 7. Verify locally

```bash
php artisan serve
php artisan queue:listen --tries=1 --timeout=0   # ticket emails
```

1. `POST /api/login` → copy JWT.
2. `POST /api/checkout` with `event_id`, `ticket_type_id`, `quantity` → returns KHQR.
3. Render `qr_payload` as a QR image (frontend).
4. Poll `GET /api/payments/{id}/status` every 10 s (or wait for the webhook).
5. When `status = paid`, tickets are issued and emailed automatically.