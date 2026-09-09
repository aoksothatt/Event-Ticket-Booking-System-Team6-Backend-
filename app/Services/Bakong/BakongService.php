<?php

namespace App\Services\Bakong;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BakongService
{
    public function __construct(private readonly BakongApi $api) {}

    /**
     * Generate a KHQR payment code for the given amount.
     *
     * @return array{md5: string, qr: string, expires_at: string}
     *
     * @throws BakongException
     */
    public function generateQr(float $amount, ?string $transactionId = null, array $metadata = []): array
    {
        $payload = [
            'qr_data' => [
                'merchant_name' => config('bakong.merchant_name'),
                'merchant_city' => $metadata['city'] ?? 'Phnum Penh',
                'account_id' => config('bakong.account_id'),
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => config('bakong.currency', 'USD'),
                'phone_number' => $metadata['phone_number'] ?? '',
                'store_label' => $metadata['store_label'] ?? config('bakong.merchant_name'),
                'terminal_label' => $metadata['terminal_label'] ?? 'ONLINE',
                'mobile_number' => $metadata['mobile_number'] ?? '',
                'bill_number' => $transactionId ?? $this->generateReference(),
                'reference1_label' => 'Booking',
                'reference1' => $metadata['booking_number'] ?? '',
                'reference2_label' => '',
                'reference2' => '',
                'expiration' => config('bakong.qr_expiration_minutes', 15),
            ],
        ];

        $this->checkConfig();

        try {
            $response = $this->api->generateQr($payload, $this->getAccessToken());
        } catch (ConnectionException $e) {
            Log::channel('bakong')->error('Bakong QR generation timed out.', [
                'booking_number' => $metadata['booking_number'] ?? null,
                'error' => $e->getMessage(),
            ]);
            throw new BakongException('The payment gateway is temporarily unavailable. Please try again.', 503, $e);
        } catch (\Throwable $e) {
            Log::channel('bakong')->error('Bakong QR generation failed.', [
                'booking_number' => $metadata['booking_number'] ?? null,
                'error' => $e->getMessage(),
            ]);
            throw new BakongException('Failed to generate QR code.', 502, $e);
        }

        $body = $response->json();

        if (! $response->successful() || (($body['responseCode'] ?? 200) !== 0)) {
            Log::channel('bakong')->warning('Bakong QR generation returned non-success.', [
                'status' => $response->status(),
                'body' => $body,
            ]);
            throw new BakongException(
                ($body['responseMessage'] ?? 'Payment gateway rejected the request.') . ' (code ' . ($body['responseCode'] ?? $response->status()) . ')',
                502
            );
        }

        $md5 = $body['data']['md5'] ?? null;

        if ($md5 === null) {
            Log::channel('bakong')->warning('Bakong QR response missing md5.', ['body' => $body]);
            throw new BakongException('Invalid response from payment gateway.', 502);
        }

        Log::channel('bakong')->info('Bakong QR generated', [
            'md5' => $md5,
            'booking_number' => $metadata['booking_number'] ?? null,
        ]);

        return [
            'md5' => $md5,
            'qr' => $body['data']['qr'] ?? $body['data']['qr_image'] ?? '',
            'expires_at' => now()->addMinutes((int) config('bakong.qr_expiration_minutes', 15))->toIso8601String(),
        ];
    }

    /**
     * Check the status of a payment transaction by MD5.
     *
     * @return array{status: string, transaction_id: ?string, raw: array}
     *
     * @throws BakongException
     */
    public function checkTransaction(string $md5): array
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
            ]);
            throw new BakongException('Payment verification failed.', 502, $e);
        }

        $body = $response->json();

        Log::channel('bakong')->info('Bakong transaction check', [
            'md5' => $md5,
            'status' => $response->status(),
            'body' => $body,
        ]);

        if (! $response->successful()) {
            throw new BakongException('Payment gateway could not process the check.', 502);
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
            if ($token !== '') {
                return $token;
            }
            throw new BakongException('Payment gateway is not configured (missing credentials).', 500);
        }

        try {
            $response = $this->api->requestAccessToken([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (\Throwable $e) {
            Log::channel('bakong')->error('Bakong access token request failed', [
                'error' => $e->getMessage(),
            ]);
            throw new BakongException('Unable to authenticate with the payment gateway.', 502, $e);
        }

        $body = $response->json();
        $token = $body['access_token'] ?? $body['token'] ?? null;

        if (! $response->successful() || ! is_string($token) || $token === '') {
            Log::channel('bakong')->warning('Bakong access token request returned no token.', ['body' => $body]);
            throw new BakongException('Unable to obtain a payment gateway token.', 502);
        }

        // Cache the token slightly before its actual expiry to avoid races.
        $ttl = (int) ($body['expires_in'] ?? 3600) - 60;
        Cache::put('bakong_access_token', $token, max($ttl, 60));

        return $token;
    }

    /**
     * Ensure the gateway is minimally configured before making a call.
     *
     * @throws BakongException
     */
    protected function checkConfig(): void
    {
        if (config('bakong.base_url') === '' || config('bakong.account_id') === '') {
            throw new BakongException('Payment gateway is not configured (missing base URL or account id).', 500);
        }
    }

    protected function generateReference(): string
    {
        return 'REF-' . strtoupper(bin2hex(random_bytes(6)));
    }
}
