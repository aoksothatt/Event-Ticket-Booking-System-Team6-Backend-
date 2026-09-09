# Bakong Payment Module — Deployment Considerations

## Architecture notes

The payment module is layered so other gateways can be added without touching controllers:

```
Controllers            CheckoutController / PaymentController
        │  (never call Bakong directly)
Services/Bakong        BakongService (business)  →  BakongApi (HTTP)
        │
PaymentVerificationService   (idempotent confirmation pipeline)
        │
Repositories/PaymentRepository   (persistence)
        v
Models          Payment / Booking / Ticket
```

To add ABA/Stripe/PayPal later: add a `*Api` + `*Service` under `app/Services/<Provider>/`,
reuse `PaymentRepository` and `PaymentVerificationService` (provider-agnostic), and swap the
`provider` value. The `Payment` model is already provider-agnostic.

## Security checklist for production

- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `BAKONG_WEBHOOK_SECRET` is long + random; match it in the Bakong portal.
- [ ] Restrict `GET|POST /payments/*` — owner-scoped checks are enforced per request.
- [ ] Keep `BAKONG_TOKEN`/`BAKONG_CLIENT_SECRET` out of the repo (`.env` only, excluded by gitignore).
- [ ] TLS enforced at the load balancer; `APP_URL` uses `https://`.
- [ ] Enable throttle on `/checkout` (`10/min`) and `/verify` (`30/min`) — already applied.

## Queue / email

- The `TicketIssued` mailable is queued (`ShouldQueue`). Run the worker:
  `php artisan queue:work --queue=default --tries=3`.
- `QUEUE_CONNECTION=database` is fine for small deployments; upgrade to Redis for scale.

## Webhook reliability

- Webhook handling is idempotent — Bakong re-deliveries are safe.
- If Bakong does not support webhooks for your plan, keep the 10-second poll as the fallback.
- `raw_response` on each `Payment` stores the gateway response history for debugging/reconciliation.

## Monitoring

- All Bakong traffic is logged to `storage/logs/bakong-YYYY-MM-DD.log` (separate channel).
- Watch for:
  - `BAKONG REQUEST FAILED` → connectivity / TLS issues.
  - `Verification failed for payment` → retry/alert.
  - `Webhook rejected: bad signature` → misconfiguration or attack.
- Add an alert on payments stuck in `pending` past their `expires_at`.

## Database

- Use `lockForUpdate` semantics (already in code) to avoid overselling under concurrency.
- Back up PostgreSQL (`pg_dump`) and the `storage/app/public/tickets` dir for ticket QR recovery.
- Indexes: `payments.bakong_md5`, `payments.booking_id`, `tickets.booking_id` are the hot paths.

## Testing in production-like environments

- Mock the Bakong API with `Http::fake()` (see `tests/Unit/BakongServiceTest.php`).
- Use the Bakong **sandbox** base URL before going live.