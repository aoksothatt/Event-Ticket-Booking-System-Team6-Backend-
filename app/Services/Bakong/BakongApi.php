<?php

namespace App\Services\Bakong;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BakongApi
 *
 * Thin, provider-specific HTTP client for the Bakong (NBC) Open API.
 * This class owns the request/response details (headers, endpoints,
 * auth, retries). It has no business logic — that lives in BakongService.
 *
 * The KHQR string itself is generated locally by the PHP KHQR SDK
 * (khqr-gateway/bakong-khqr-php); this client never asks Bakong to
 * generate a QR code — that endpoint does not exist. It is only used for
 * the endpoints Bakong actually exposes: tokens, deeplinks, and
 * transaction lookups.
 */
class BakongApi
{
    public function __construct(private readonly string $baseUrl, private readonly int $timeout) {}

    /**
     * Request an OAuth access token from Bakong.
     */
    public function requestAccessToken(array $credentials): Response
    {
        return $this->send(
            'post',
            '/requestToken',
            $credentials,
            [] // no bearer token needed for token request
        );
    }

    /**
     * Generate a wallet deeplink for a locally-generated KHQR string.
     *
     * The deeplink endpoint receives the completed KHQR and returns a short
     * link that opens the payment in a supported mobile wallet app. It does
     * NOT generate (or need to generate) the KHQR itself.
     *
     * Per the Bakong Open API spec this endpoint does not require a bearer
     * token; we still accept one so callers can choose to send it.
     */
    public function generateDeeplink(string $khqr, ?array $sourceInfo = null, ?string $token = null): Response
    {
        $payload = ['qr' => $khqr];

        if (is_array($sourceInfo) && $sourceInfo !== []) {
            $payload['sourceInfo'] = $sourceInfo;
        }

        return $this->send('post', '/generate_deeplink_by_qr', $payload, $token);
    }

    /**
     * Check the status of a transaction by its MD5 hash.
     */
    public function checkPaymentByMd5(string $md5, ?string $token = null): Response
    {
        return $this->send('post', '/check_transaction_by_md5', ['md5' => $md5], $token);
    }

    /**
     * Check the status of a transaction by its Bakong transaction id.
     */
    public function checkPaymentByTransactionId(string $transactionId, ?string $token = null): Response
    {
        return $this->send('post', '/check_transaction_by_id', ['id' => $transactionId], $token);
    }

    /**
     * Send an HTTP request to the Bakong API with retry and logging.
     */
    protected function send(string $method, string $path, array $data, ?string $token = null): Response
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        Log::channel('bakong')->info('BAKONG REQUEST', [
            'method' => $method,
            'url' => $url,
            'payload' => $data,
            'has_token' => $token !== null && $token !== '',
        ]);

        $request = Http::timeout($this->timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Time-Zone' => config('app.timezone', 'UTC'),
            ]);

        if ($token !== null && $token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        try {
            $response = $request->{$method}($url, $data);

            $logBody = $response->json() ?? ['_raw' => $response->body()];

            Log::channel('bakong')->info('BAKONG RESPONSE', [
                'status' => $response->status(),
                'body' => $logBody,
            ]);

            return $response;
        } catch (\Throwable $e) {
            Log::channel('bakong')->error('BAKONG REQUEST FAILED', [
                'url' => $url,
                'method' => $method,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
