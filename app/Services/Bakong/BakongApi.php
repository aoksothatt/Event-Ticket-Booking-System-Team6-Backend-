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
     * Generate a KHQR payment code.
     */
    public function generateQr(array $payload, ?string $token = null): Response
    {
        return $this->send('post', '/KHQR/generate', $payload, $token);
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
        ]);

        $request = Http::timeout($this->timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Time-Zone' => config('app.timezone', 'UTC'),
            ]);

        if ($token !== null && $token !== '') {
            $request->withHeaders(['Authorization' => 'Bearer ' . $token]);
        }

        try {
            $response = $request->{$method}($url, $data);

            Log::channel('bakong')->info('BAKONG RESPONSE', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return $response;
        } catch (\Throwable $e) {
            Log::channel('bakong')->error('BAKONG REQUEST FAILED', [
                'url' => $url,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
