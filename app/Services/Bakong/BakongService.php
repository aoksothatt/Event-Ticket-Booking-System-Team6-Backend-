<?php

namespace App\Services\Bakong;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use KHQR\BakongKHQR;
use KHQR\Exceptions\KHQRException;
use KHQR\Helpers\KHQRData;
use KHQR\Models\IndividualInfo;

class BakongService
{
    public function __construct(private readonly BakongApi $api) {}

    /**
     * Generate a KHQR payment code LOCALLY using the PHP KHQR SDK.
     *
     * This replaces the previous approach of calling a (non-existent)
     * server-side Bakong QR generation endpoint. The KHQR string is built
     * from the configured merchant information, then the MD5 is computed
     * from the exact KHQR string so it can be used later with
     * /v1/check_transaction_by_md5.
     *
     * @return array{khqr: string, md5: string, expires_at: string}
     *
     * @throws BakongException
     */
    public function generateKhqr(float $amount, ?string $transactionId = null, array $metadata = []): array
    {
        $this->checkConfig();

        $currency = strtoupper(config('bakong.currency', 'USD'));
        $currencyCode = config('bakong.currency_codes.' . $currency, KHQRData::CURRENCY_USD);

        $info = new IndividualInfo(
            bakongAccountID: $this->accountId(),
            merchantName: $this->merchantName(),
            merchantCity: config('bakong.merchant_city') ?: 'Phnom Penh',
            currency: (int) $currencyCode,
            amount: $amount,
            billNumber: $transactionId ?? $this->generateReference(),
            storeLabel: $this->merchantName(),
            terminalLabel: $metadata['terminal'] ?? 'ONLINE',
        );

        try {
            $response = BakongKHQR::generateIndividual($info);
        } catch (KHQRException|BakongException $e) {
            Log::channel('bakong')->error('KHQR generation failed.', [
                'booking_number' => $metadata['booking_number'] ?? null,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            throw new BakongException('Unable to generate payment QR.', 502, $e);
        } catch (\Throwable $e) {
            Log::channel('bakong')->error('KHQR generation failed (unexpected).', [
                'booking_number' => $metadata['booking_number'] ?? null,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            throw new BakongException('Unable to generate payment QR.', 500, $e);
        }

        $status = $response->status;
        $data = $response->data;

        if (($status['code'] ?? 1) !== 0 || ! is_array($data) || ! isset($data['qr'])) {
            Log::channel('bakong')->error('KHQR SDK returned a failure status.', [
                'booking_number' => $metadata['booking_number'] ?? null,
                'status' => $status,
                'data' => $data,
            ]);
            throw new BakongException('Unable to generate payment QR.', 502);
        }

        $khqr = $data['qr'];
        $md5 = $data['md5'] ?? md5($khqr);

        // The bundled PHP SDK (v1.0.0) emits tag 99 with ONLY the creation
        // timestamp. The current Bakong spec requires dynamic KHQRs to embed
        // BOTH creation (99.00) and expiration (99.01) timestamps — mobile
        // apps (ABA, Bakong, …) reject dynamic QRs lacking the expiry with
        // "Invalid QR" (SDK error #45 "Expiration timestamp is required for
        // dynamic KHQR"). The SDK cannot encode the expiry, so we post-process
        // ONLY tag 99 (adding creation + expiry), keep every other TLV from
        // the SDK untouched, and recompute the CRC. This is not a replacement
        // of the KHQR SDK — it fixes the SDK's spec gap in output.
        $finalQr = $this->applyExpirationTimestamp($khqr);

        if ($finalQr !== $khqr) {
            $khqr = $finalQr;
            $md5 = md5($khqr);
        }

        // TEMPORARY DIAGNOSTIC (non-secret only). Do not remove until the
        // real-Bakong scan/pay test passes. Never add tokens/secrets here.
        try {
            $parsed = (array) (BakongKHQR::decode($khqr)->data ?? []);
        } catch (\Throwable $e) {
            $parsed = ['parse_error' => $e->getMessage()];
        }
        Log::channel('bakong')->debug('KHQR payload diagnostic.', [
            'booking_number' => $metadata['booking_number'] ?? null,
            'merchant_account_id' => $this->accountId(),
            'merchant_name' => $this->merchantName(),
            'merchant_city' => config('bakong.merchant_city') ?: 'Phnom Penh',
            'currency' => $currency,
            'currency_code' => $currencyCode,
            'amount' => $amount,
            'bill_number' => $transactionId ?? $this->generateReference(),
            'expiration_minutes' => (int) config('bakong.qr_expiration_minutes', 15),
            'khqr_length' => strlen($khqr),
            'md5' => $md5,
            'parsed_fields' => $parsed,
        ]);

        Log::channel('bakong')->info('KHQR generated locally.', [
            'booking_number' => $metadata['booking_number'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'md5' => $md5,
        ]);

        return [
            'khqr' => $khqr,
            'md5' => $md5,
            'expires_at' => now()->addMinutes((int) config('bakong.qr_expiration_minutes', 15))->toIso8601String(),
        ];
    }

    /**
     * Add the spec-required expiration timestamp to a dynamic KHQR.
     *
     * The bundled PHP SDK emits tag 99 with only the creation timestamp
     * (99.00). Current Bakong/ABA apps require the expiration timestamp
     * (99.01) as well; without it, dynamic QRs decode as invalid (SDK error
     * #45). This rebuilds ONLY tag 99 as {00 = creation, 01 = expiry},
     * leaving every other tag from the SDK untouched, then recomputes the
     * CRC over the full string. Returns the original string when there is
     * nothing to fix.
     */
    private function applyExpirationTimestamp(string $khqr): string
    {
        $expiryMinutes = (int) config('bakong.qr_expiration_minutes', 15);

        $segments = $this->parseTlv($khqr);

        if ($segments === null) {
            return $khqr;
        }

        $rebuilt = false;

        foreach ($segments as $i => $segment) {
            if ($segment['tag'] !== '99') {
                continue;
            }

            $inner = $this->parseTlv($segment['value']);
            $creation = $inner['00']['value'] ?? null;

            if ($inner === null || $creation === null || ! ctype_digit($creation)) {
                return $khqr;
            }

            $expiry = ((int) $creation) + ($expiryMinutes * 60 * 1000);

            $inner['00'] = (string) $creation;
            $inner['01'] = (string) $expiry;
            ksort($inner, SORT_STRING);

            $segments[$i]['value'] = $this->serializeTlv($inner);
            $segments[$i]['len'] = strlen($segments[$i]['value']);
            $rebuilt = true;

            break;
        }

        if (! $rebuilt) {
            return $khqr;
        }

        $withoutCrc = substr($this->serializeTlv($segments), 0, -4);

        return $withoutCrc . \KHQR\Helpers\Utils::crc16($withoutCrc);
    }

    /**
     * Split a TLV payload into [tag => 'TT', len => int, value => string].
     *
     * @return array<int, array{tag: string, len: int, value: string}>|null
     */
    private function parseTlv(string $payload): ?array
    {
        $segments = [];
        $rest = $payload;

        while ($rest !== '') {
            if (strlen($rest) < 4) {
                return null;
            }

            $tag = substr($rest, 0, 2);
            $length = (int) substr($rest, 2, 2);
            $value = substr($rest, 4, $length);

            if (strlen($value) !== $length || strlen($rest) < 4 + $length) {
                return null;
            }

            $segments[$tag] = ['tag' => $tag, 'len' => $length, 'value' => $value];
            $rest = substr($rest, 4 + $length);
        }

        return $segments;
    }

    /**
     * Serialize a list of TLV segments back into a compact string.
     *
     * @param  array<int, array{tag: string, len: int, value: string}>|array<string, string>  $segments
     */
    private function serializeTlv(array $segments): string
    {
        $out = '';

        foreach ($segments as $tag => $segment) {
            if (is_array($segment) && isset($segment['tag'])) {
                $out .= $segment['tag'] . str_pad((string) $segment['len'], 2, '0', STR_PAD_LEFT) . $segment['value'];
            } else {
                $out .= $tag . str_pad((string) strlen($segment), 2, '0', STR_PAD_LEFT) . $segment;
            }
        }

        return $out;
    }

    /**
     * Ask Bakong for a wallet deeplink for an already-generated KHQR string.
     *
     * This is OPTIONAL: if Bakong is unreachable or rejects the request we
     * return null so the regular KHQR payment flow can continue. It never
     * fails the checkout on its own.
     *
     * @return string|null The short link, or null when unavailable.
     */
    public function generateDeeplink(string $khqr, ?array $metadata = []): ?string
    {
        if ($khqr === '' || config('bakong.base_url') === '') {
            return null;
        }

        $sourceInfo = $this->buildSourceInfo();

        try {
            $response = $this->api->generateDeeplink($khqr, $sourceInfo);
        } catch (ConnectionException $e) {
            Log::channel('bakong')->info('Deeplink generation skipped: gateway timeout.', [
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::channel('bakong')->info('Deeplink generation skipped: request failed.', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return null;
        }

        $body = $response->json();

        if (! $response->successful() || ($body['responseCode'] ?? null) !== 0) {
            Log::channel('bakong')->info('Deeplink generation skipped: non-success response.', [
                'status' => $response->status(),
                'body' => $body,
            ]);
            return null;
        }

        $shortLink = (string) ($body['data']['shortLink'] ?? '');

        if ($shortLink === '') {
            Log::channel('bakong')->info('Deeplink generation skipped: no shortLink in response.');
            return null;
        }

        Log::channel('bakong')->info('Deeplink generated.', [
            'shortLink' => $shortLink,
        ]);

        return $shortLink;
    }

    /**
     * Check the status of a payment transaction by the MD5 of its KHQR.
     *
     * Uses Bakong's /v1/check_transaction_by_md5 — the only supported way to
     * verify payment by the KHQR. Mapping to internal statuses happens through
     * config('bakong.status_map').
     *
     * A 401 response is treated specially: the cached access token is
     * invalidated (so OAuth callers can refresh on the next request) and a
     * clean, actionable error is thrown instead of pretending the check
     * "just failed". The payment is never marked paid either way.
     *
     * The Bakong transaction's `amount` and `currency` are returned alongside
     * the status so callers can verify the transaction actually matches the
     * expected payment before confirming a booking.
     *
     * @return array{status: string, transaction_id: ?string, amount: mixed, currency: mixed, raw: array}
     *
     * @throws BakongException
     */
    public function checkTransactionByMd5(string $md5): array
    {
        $this->checkConfig();

        try {
            $response = $this->api->checkPaymentByMd5($md5, $this->getAccessToken());
        } catch (ConnectionException $e) {
            Log::channel('bakong')->error('Bakong transaction check timed out.', [
                'md5' => $md5,
                'error' => $e->getMessage(),
            ]);
            throw new BakongException('Payment verification timed out. Please try again.', 503, $e);
        } catch (\Throwable $e) {
            Log::channel('bakong')->error('Bakong transaction check failed.', [
                'md5' => $md5,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            throw new BakongException('Payment verification failed.', 502, $e);
        }

        $body = $response->json();

        Log::channel('bakong')->info('Bakong transaction check.', [
            'md5' => $md5,
            'status' => $response->status(),
            'body' => $body,
        ]);

        if (! $response->successful()) {
            $status = $response->status();

            if ($status === 401) {
                // Invalid/expired credential. Drop any cached token so the next
                // request starts fresh (OAuth refresh) instead of hammering the
                // gateway with the same expired token forever.
                Cache::forget('bakong_access_token');

                Log::channel('bakong')->error('Bakong authentication failed (401). The configured API credential is invalid or expired.', [
                    'md5' => $md5,
                    'status' => $status,
                ]);

                throw new BakongException(
                    'Bakong authentication failed. Please configure a valid Bakong API credential.',
                    401
                );
            }

            Log::channel('bakong')->error('Bakong transaction check returned non-success.', [
                'md5' => $md5,
                'status' => $status,
                'body' => $body,
            ]);
            throw new BakongException(
                'Payment gateway could not process the check.'
                . ' (HTTP ' . $status . ')'
                . (isset($body['responseMessage']) ? ' — ' . $body['responseMessage'] : ''),
                $status
            );
        }

        // Bakong replies HTTP 200 even for application-level failures, e.g.
        // {"responseCode":1,"errorCode":17,"responseMessage":"Daily request
        // limit of 100 exceeded...","data":null}. Without this guard that
        // falls through to status "pending", so a rate-limited (or otherwise
        // failed) check looks like an unpaid payment and the QR never
        // resolves. Surface it as a transient gateway error instead.
        if ((int) ($body['responseCode'] ?? 0) !== 0) {
            $message = (string) ($body['responseMessage'] ?? 'The payment gateway could not check the transaction.');

            Log::channel('bakong')->warning('Bakong returned an application-level error.', [
                'md5' => $md5,
                'responseCode' => $body['responseCode'] ?? null,
                'errorCode' => $body['errorCode'] ?? null,
                'message' => $message,
            ]);

            throw new BakongException('Payment gateway is temporarily unavailable: ' . $message, 503);
        }

        $bakongStatus = strtoupper((string) ($body['data']['status'] ?? ($body['status'] ?? '')));
        $statusMap = config('bakong.status_map', []);
        $status = $statusMap[$bakongStatus] ?? 'pending';

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        return [
            'status' => $status,
            'transaction_id' => $data['bakong_transaction_id']
                ?? $data['transaction_id']
                ?? $body['bakong_transaction_id']
                ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'raw' => $body,
        ];
    }

    /**
     * Return the OAuth access token, refreshing/caching it as needed.
     */
    protected function getAccessToken(): string
    {
        $cached = Cache::get('bakong_access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->refreshAccessToken();
    }

    /**
     * Request a fresh access token from Bakong and cache it.
     *
     * @throws BakongException
     */
    protected function refreshAccessToken(): string
    {
        $clientId = config('bakong.client_id');
        $clientSecret = config('bakong.client_secret');

        // Fallback: if no client_id/client_secret are configured, we can use
        // a pre-provisioned token (BAKONG_TOKEN) directly.
        if ($clientId === '' && $clientSecret === '') {
            $token = config('bakong.token');
            if (is_string($token) && $token !== '') {
                return $token;
            }
            throw new BakongException(
                'Payment gateway is not configured: Set either BAKONG_CLIENT_ID + BAKONG_CLIENT_SECRET, '
                . 'or BAKONG_TOKEN in your .env file.',
                500
            );
        }

        try {
            $response = $this->api->requestAccessToken([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (\Throwable $e) {
            Log::channel('bakong')->error('Bakong access token request failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            throw new BakongException('Unable to authenticate with the payment gateway.', 502, $e);
        }

        $body = $response->json();
        $token = $body['access_token'] ?? $body['token'] ?? null;

        if (! $response->successful() || ! is_string($token) || $token === '') {
            Log::channel('bakong')->warning('Bakong access token request returned no token.', [
                'status' => $response->status(),
                'body' => $body,
            ]);
            throw new BakongException('Unable to obtain a payment gateway token.', 502);
        }

        // Cache the token slightly before its actual expiry to avoid races.
        $ttl = (int) ($body['expires_in'] ?? 3600) - 60;
        Cache::put('bakong_access_token', $token, max($ttl, 60));

        return $token;
    }

    /**
     * Build the optional sourceInfo object for the deeplink request.
     * Returns null when no app branding is configured.
     *
     * @return array<string, string>|null
     */
    protected function buildSourceInfo(): ?array
    {
        $icon = config('bakong.app_icon_url');
        $name = config('bakong.app_name');
        $callback = config('bakong.app_deep_link_callback');

        if ($icon === '' && $name === '' && $callback === '') {
            return null;
        }

        // The API requires all three fields together when sourceInfo is sent.
        return [
            'appIconUrl' => $icon,
            'appName' => $name ?: 'Laravel Event Booking',
            'appDeepLinkCallback' => $callback ?: config('app.url', ''),
        ];
    }

    /**
     * Ensure the gateway is minimally configured before making a call.
     *
     * @throws BakongException
     */
    protected function checkConfig(): void
    {
        if (config('bakong.base_url') === '') {
            throw new BakongException('Payment gateway is not configured: BAKONG_BASE_URL is missing.', 500);
        }

        if ($this->accountId() === '') {
            throw new BakongException('Payment gateway is not configured: BAKONG_ACCOUNT (or BAKONG_MERCHANT_ID) is missing. This should be your Bakong-registered account ID.', 500);
        }

        if ($this->merchantName() === '') {
            throw new BakongException('Payment gateway is not configured: BAKONG_MERCHANT_NAME is missing. This should be a human-readable display name (not an email).', 500);
        }

        $merchantName = $this->merchantName();
        if (str_contains($merchantName, '@')) {
            Log::channel('bakong')->warning('BAKONG_MERCHANT_NAME looks like an email address. It should be a display name like "Aok Sothatt".', [
                'merchant_name' => $merchantName,
            ]);
        }
    }

    /**
     * Resolve the Bakong account ID used inside the KHQR.
     *
     * Preference order: BAKONG_ACCOUNT, then BAKONG_MERCHANT_ID (both hold
     * the merchant's Bakong-registered account, e.g. "user@bkrt").
     */
    protected function accountId(): string
    {
        return (string) (config('bakong.account_id') ?: config('bakong.merchant_id') ?: '');
    }

    /**
     * Resolve the human-readable merchant display name placed on the KHQR.
     *
     * Preference order: BAKONG_MERCHANT_NAME, then BAKONG_MERCHANT (ignored
     * when it looks like an email), then a safe default derived from
     * app.name so a missing display name never bricks the checkout. The
     * merchant name is informational only on the QR.
     */
    protected function merchantName(): string
    {
        $name = (string) config('bakong.merchant_name');

        if ($name === '') {
            $name = (string) config('bakong.merchant');
            if ($name === '' || str_contains($name, '@')) {
                $name = trim((string) config('app.name', 'Event Booking'), '"');
                Log::channel('bakong')->warning('BAKONG_MERCHANT_NAME not set — falling back to APP_NAME for the KHQR display name.', [
                    'merchant_name' => $name,
                ]);
            }
        }

        return $name;
    }

    protected function generateReference(): string
    {
        return 'PAY-' . now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}