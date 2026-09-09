# Bakong Payment — Frontend Integration (Vue 3)

Full flow: select event → pick ticket type → quantity → **`POST /api/checkout`**
→ show KHQR + countdown → poll **`POST /api/payments/{id}/verify`** every ~15 s
→ paid? → success page + tickets.

> The `GET /api/payments/{id}/status` endpoint is **local-only** — it never asks
> Bakong. `verify` is the call that actually checks the transaction and, if
> paid, confirms the booking and issues tickets (idempotent server-side). Use
> `verify` for the authoritative loop, `status` only for display.

The API client is already added at `src/api/bakongApi.js` (uses the existing
`src/api/http.js` axios instance, so JWT auth and 401 handling are automatic).

## 1. Install a QR renderer

```bash
npm install qrcode          # or: npm install vue-qrcode
```

Render the returned `qr_payload` string (it is the raw KHQR data, not a URL):

```html
<!-- with vue-qrcode -->
<QrcodeVue :value="qrPayload" :size="240" level="H" />
```

or with the `qrcode` package:

```js
import QRCode from "qrcode";
await QRCode.toDataURL(qrPayload, { errorCorrectionLevel: "H" });
```

## 2. Checkout + payment component

```vue
<script setup>
import { onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import {
  createBakongCheckout,
  verifyPayment,
  formatCountdown,
} from "../api/bakongApi";

const props = defineProps({
  eventId: { type: Number, required: true },
  ticketTypeId: { type: Number, required: true },
});

const router = useRouter();
const busy = ref(false);
const error = ref("");
const qrPayload = ref("");
const amount = ref(0);
const currency = ref("USD");
const expiresAt = ref(null);
const paymentId = ref(null);
const countdown = ref("15:00");
const status = ref("pending");

let verifyTimer = null;
let countdownTimer = null;

onMounted(async () => {
  try {
    busy.value = true;
    // 2 × admissions (or pass a chosen quantity)
    const res = await createBakongCheckout({
      event_id: props.eventId,
      ticket_type_id: props.ticketTypeId,
      quantity: 2,
    });

    qrPayload.value = res.data.qr_payload;
    amount.value = Number(res.data.amount);
    currency.value = res.data.currency;
    expiresAt.value = res.data.expires_at;
    paymentId.value = res.data.payment.id;

    startCountdown();
    startVerifyLoop();
  } catch (e) {
    error.value = e?.response?.data?.message || "Unable to start checkout.";
  } finally {
    busy.value = false;
  }
});

function startVerifyLoop() {
  // `verify` checks Bakong and is idempotent — safe to call on an interval.
  verifyTimer = setInterval(async () => {
    try {
      const res = await verifyPayment(paymentId.value);
      status.value = res.data.status;
      if (status.value === "paid") {
        stopTimers();
        router.push({ name: "payment-success", params: { booking: res.data.booking_id } });
      } else if (status.value === "expired" || status.value === "failed") {
        stopTimers();
      }
    } catch {
      /* transient — keep polling */
    }
  }, 15000);
}

function startCountdown() {
  const tick = () => {
    countdown.value = formatCountdown(expiresAt.value);
    if (countdown.value === "00:00") {
      stopTimers();
      verifyPayment(paymentId.value).catch(() => {});
    }
  };
  tick();
  countdownTimer = setInterval(tick, 1000);
}

function stopTimers() {
  clearInterval(verifyTimer);
  clearInterval(countdownTimer);
  verifyTimer = null;
  countdownTimer = null;
}

onBeforeUnmount(stopTimers);
</script>

<template>
  <div class="max-w-md mx-auto p-6 border rounded-lg">
    <h2 class="text-lg font-semibold">Scan to pay</h2>

    <p class="text-2xl font-bold">{{ amount.toFixed(2) }} {{ currency }}</p>
    <p class="text-sm text-gray-500">Expires in {{ countdown }}</p>

    <div class="my-4 flex justify-center">
      <canvas v-if="qrPayload" ref="qrCanvas" :data-payload="qrPayload" />
    </div>

    <p class="text-sm text-center text-gray-600">
      Use the Bakong / ABA / WING app to scan the KHQR.<br />
      Payment status: <strong>{{ status }}</strong>
    </p>

    <p v-if="error" class="text-red-500 text-sm mt-2">{{ error }}</p>
    <p v-if="busy" class="text-sm mt-2">Preparing payment…</p>
  </div>
</template>
```

> Replace the `<canvas>` with the QR rendering library of your choice — the
> component above just stores the payload; render it with `vue-qrcode` or the
> example from section 1.

## 3. Webhook vs polling

Both paths converge on the same backend logic:

- **Preferred:** Bakong calls `POST /api/payments/webhook` (header
  `X-Bakong-Signature`) → confirmation happens server-side → your next poll
  returns `paid`.
- **Fallback:** the explicit `verify` call is idempotent, so the timeout
  handler and the webhook can never double-issue tickets.