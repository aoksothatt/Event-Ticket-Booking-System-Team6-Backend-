# Bakong Payment Module — API Documentation

Base URL: `http://localhost:8000/api` (dev) — adjust for production.

Authentication: `Authorization: Bearer <JWT>` on all endpoints except the webhook.

## Endpoints

| Method | URI | Auth | Purpose |
|--------|-----|------|---------|
| `POST` | `/checkout` | JWT (customer) | Create a pending booking + payment and generate a KHQR to scan. |
| `GET` | `/payments/{payment}` | JWT (owner) | Full payment record. |
| `GET` | `/payments/{payment}/status` | JWT (owner) | Lightweight polling status (frontend uses this every 5–10 s). |
| `POST` | `/payments/{payment}/verify` | JWT (owner) | Manually trigger Bakong verification + confirmation. |
| `POST` | `/payments/webhook` | Shared secret header | Bakong async callback. Replay-safe. |

---

## 1. POST /checkout

Initiates the purchase. Creates `Booking` (pending) + `Payment` (pending), calls Bakong to generate a KHQR, and returns the QR payload + expiry.

### Request

Headers:

```
Authorization: Bearer <jwt>
Content-Type: application/json
```

Body:

```json
{
  "event_id": 12,
  "ticket_type_id": 33,
  "quantity": 2
}
```

| Field | Type | Rules |
|-------|------|-------|
| `event_id` | int | required, exists:events |
| `ticket_type_id` | int | required, exists:ticket_types |
| `quantity` | int | required, min 1, max 10 |

Rate limit: `10/min` per user.

### Response `201 Created`

```json
{
  "success": true,
  "message": "Checkout initiated. Scan the KHQR to pay.",
  "data": {
    "booking": {
      "id": 57,
      "booking_number": "BK-8K2X9PABCD",
      "user_id": 4,
      "event_id": 12,
      "booking_date": "2026-09-09 03:00:00",
      "total_amount": "51.00",
      "discount": "0.00",
      "service_fee": "0.00",
      "status": "pending",
      "items": [ { "ticket_type_id": 33, "quantity": 2, "unit_price": "25.50", "subtotal": "51.00" } ]
    },
    "payment": {
      "id": 91,
      "booking_id": 57,
      "provider": "bakong",
      "transaction_reference": "TXN-9PW4JU8ZKM2A",
      "bakong_md5": "8f14e45fceea167a5a36dedd4bea2543",
      "currency": "USD",
      "amount": "51.00",
      "status": "pending",
      "expires_at": "2026-09-09T03:15:00.000000Z"
    },
    "qr_payload": "00020101021229370012...",
    "expires_at": "2026-09-09T03:15:00.000000Z",
    "amount": "51.00",
    "currency": "USD",
    "poll_interval_seconds": 10
  }
}
```

### Errors

| Code | Condition |
|------|-----------|
| 401 | Missing/invalid JWT |
| 422 | Validation failed / ticket type–event mismatch / sold out / quantity > available |
| 429 | Checkout already in progress (lock) or rate limit |
| 502 | Bakong gateway rejected the QR request |
| 503 | Bakong gateway timeout / unavailable |

---

## 2. GET /payments/{payment}

Returns the full payment record.

### Response `200 OK`

```json
{
  "success": true,
  "data": {
    "payment": {
      "id": 91,
      "booking_id": 57,
      "provider": "bakong",
      "transaction_reference": "TXN-9PW4JU8ZKM2A",
      "bakong_md5": "8f14e45fceea167a5a36dedd4bea2543",
      "bakong_transaction_id": null,
      "qr_payload": "00020101021229370012...",
      "currency": "USD",
      "amount": "51.00",
      "status": "pending",
      "paid_at": null,
      "expires_at": "2026-09-09T03:15:00.000000Z"
    },
    "status": "pending",
    "is_expired": false
  }
}
```

---

## 3. GET /payments/{payment}/status

Lightweight polling endpoint. The frontend calls this every 5–10 seconds.

### Response `200 OK`

```json
{
  "success": true,
  "data": {
    "payment_id": 91,
    "status": "paid",
    "is_expired": false,
    "paid_at": "2026-09-09T03:02:11.000000Z",
    "expires_at": "2026-09-09T03:15:00.000000Z",
    "booking_id": 57,
    "booking_status": "paid"
  }
}
```

Status values (payment): `pending`, `paid`, `failed`, `expired`, `cancelled`.

---

## 4. POST /payments/{payment}/verify

Manually triggers verification against Bakong. When the customer has paid:

- payment → `paid`, `bakong_transaction_id`, `paid_at`
- booking → `paid`
- tickets are generated (ticket_number, QR image stored)
- ticket email is sent

Idempotent: safe to call repeatedly.

### Response `200 OK`

```json
{
  "success": true,
  "data": {
    "payment": {
      "id": 91,
      "booking_id": 57,
      "provider": "bakong",
      "transaction_reference": "TXN-9PW4JU8ZKM2A",
      "bakong_md5": "8f14e45fceea167a5a36dedd4bea2543",
      "bakong_transaction_id": "TXN-BAKONG-9001",
      "status": "paid",
      "paid_at": "2026-09-09T03:02:11.000000Z"
    },
    "status": "paid",
    "booking_status": "paid",
    "tickets_generated": true,
    "tickets": [
      {
        "id": 12,
        "ticket_number": "EVT-2026-000042",
        "ticket_code": "TKT-8K2X9P",
        "status": "active",
        "qr_code": "tickets/qr-abc...png"
      }
    ]
  }
}
```

---

## 5. POST /payments/webhook

Bakong notifies the backend asynchronously. Public route authenticated by the `X-Bakong-Signature` header matching `BAKONG_WEBHOOK_SECRET`. Runs the same idempotent confirmation pipeline as `/verify`, so re-delivered webhooks never double-confirm or duplicate tickets.

### Request

Headers:

```
X-Bakong-Signature: <BAKONG_WEBHOOK_SECRET>
Content-Type: application/json
```

Body:

```json
{ "md5": "8f14e45fceea167a5a36dedd4bea2543" }
```

### Response `200 OK`

```json
{
  "success": true,
  "data": {
    "payment_id": 91,
    "status": "paid",
    "booking_status": "paid"
  }
}
```

### Errors

| Code | Condition |
|------|-----------|
| 403 | Signature mismatch |
| 422 | Missing `md5` |
| 404 | No payment with that `md5` |

---

## Security notes

- Amounts are always recomputed server-side from `ticket_types.price` — never trusted from the client.
- All payment confirmation is idempotent (row-level `lockForUpdate` + early return when already paid).
- Checkout is protected against double-submit via a short-lived `Cache::lock` keyed on the user + event + ticket type.
- Credentials live in `.env` only (`config/bakong.php`).
- Verification/polling routes are owner-scoped (403 for other users).