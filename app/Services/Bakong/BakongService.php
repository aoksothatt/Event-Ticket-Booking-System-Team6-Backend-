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
            bakongAccountID: config('bakong.account_id'),
            merchantName: config('bakong.merchant_name'),
            merchantCity: config('bakong.merchant_city') ?: 'Phnom Penh',
            currency: (int) $currencyCode,
            amount: $amount,
            billNumber: $transactionId ?? $this->generateReference(),
            storeLabel: config('bakong.merchant_name'),
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
     * @return array{status: string, transaction_id: ?string, raw: array}
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
            Log::channel('bakong')->error('Bakong transaction check returned non-success.', [
                'md5' => $md5,
                'status' => $response->status(),
                'body' => $body,
            ]);
            throw new BakongException(
                'Payment gateway could not process the check.'
                . ' (HTTP ' . $response->status() . ')'
                . (isset($body['responseMessage']) ? ' — ' . $body['responseMessage'] : ''),
                $response->status()
            );
        }

        $bakongStatus = strtoupper((string) ($body['data']['status'] ?? ($body['status'] ?? '')));
        $statusMap = config('bakong.status_map', []);
        $status = $statusMap[$bakongStatus] ?? 'pending';

        return [
            'status' => $status,
            'transaction_id' => $body['data']['bakong_transaction_id'] ?? $body['data']['transaction_id'] ?? null,
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

        if (config('bakong.account_id') === '') {
            throw new BakongException('Payment gateway is not configured: BAKONG_ACCOUNT is missing. This should be your Bakong-registered account ID.', 500);
        }

        if (config('bakong.merchant_name') === '') {
            throw new BakongException('Payment gateway is not configured: BAKONG_MERCHANT_NAME is missing. This should be a human-readable display name (not an email).', 500);
        }

        $merchantName = config('bakong.merchant_name');
        if (str_contains($merchantName, '@')) {
            Log::channel('bakong')->warning('BAKONG_MERCHANT_NAME looks like an email address. It should be a display name like "Aok Sothatt".', [
                'merchant_name' => $merchantName,
            ]);
        }
    }

    protected function generateReference(): string
    {
        return 'REF-' . strtoupper(bin2hex(random_bytes(6)));
    }
}