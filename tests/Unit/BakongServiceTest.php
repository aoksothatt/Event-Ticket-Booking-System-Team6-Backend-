<?php

namespace Tests\Unit;

use App\Services\Bakong\BakongApi;
use App\Services\Bakong\BakongException;
use App\Services\Bakong\BakongService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BakongServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['bakong.base_url' => 'https://api-bakong.nbc.gov.kh/v1']);
        config(['bakong.merchant_name' => 'EventBooking']);
        config(['bakong.account_id' => 'acct_123']);
        config(['bakong.currency' => 'USD']);
        config(['bakong.token' => 'test-token']);
        config(['bakong.qr_expiration_minutes' => 15]);
        config(['bakong.status_map' => [
            'COMPLETED' => 'paid',
            'FAILED' => 'failed',
            'PENDING' => 'pending',
            'TIMEOUT' => 'expired',
        ]]);
        Cache::flush();
    }

    private function serviceWithFake(array $responses): BakongService
    {
        Http::fake($responses);

        $api = new BakongApi('https://api-bakong.nbc.gov.kh/v1', 30);

        return new BakongService($api);
    }

    public function test_generate_qr_returns_md5_and_qr_payload(): void
    {
        $service = $this->serviceWithFake([
            '*/KHQR/generate' => Http::response([
                'responseCode' => 0,
                'data' => [
                    'md5' => 'abc123def456',
                    'qr' => '00020101021229370012...',
                ],
            ], 200, ['Authorization' => 'Bearer test-token']),
        ]);

        $result = $service->generateQr(25.50);

        $this->assertSame('abc123def456', $result['md5']);
        $this->assertSame('00020101021229370012...', $result['qr']);
        $this->assertNotEmpty($result['expires_at']);
    }

    public function test_generate_qr_throws_when_response_code_not_zero(): void
    {
        $this->expectException(BakongException::class);

        $service = $this->serviceWithFake([
            '*/KHQR/generate' => Http::response([
                'responseCode' => 13,
                'responseMessage' => 'Invalid merchant',
            ], 200),
        ]);

        $service->generateQr(10.00);
    }

    public function test_generate_qr_throws_when_md5_missing(): void
    {
        $this->expectException(BakongException::class);

        $service = $this->serviceWithFake([
            '*/KHQR/generate' => Http::response([
                'responseCode' => 0,
                'data' => ['qr' => '000201...'],
            ], 200),
        ]);

        $service->generateQr(10.00);
    }

    public function test_check_transaction_maps_completed_to_paid(): void
    {
        $service = $this->serviceWithFake([
            '*/check_transaction_by_md5' => Http::response([
                'data' => ['status' => 'COMPLETED', 'bakong_transaction_id' => 'TXN-9-01'],
            ], 200),
        ]);

        $result = $service->checkTransaction('abc123');

        $this->assertSame('paid', $result['status']);
        $this->assertSame('TXN-9-01', $result['transaction_id']);
    }

    public function test_check_transaction_maps_failed(): void
    {
        $service = $this->serviceWithFake([
            '*/check_transaction_by_md5' => Http::response(['data' => ['status' => 'FAILED']], 200),
        ]);

        $this->assertSame('failed', $service->checkTransaction('abc')['status']);
    }

    public function test_check_transaction_maps_pending(): void
    {
        $service = $this->serviceWithFake([
            '*/check_transaction_by_md5' => Http::response(['data' => ['status' => 'PENDING']], 200),
        ]);

        $this->assertSame('pending', $service->checkTransaction('abc')['status']);
    }

    public function test_check_transaction_throws_on_http_error(): void
    {
        $this->expectException(BakongException::class);

        $service = $this->serviceWithFake([
            '*/check_transaction_by_md5' => Http::response(['error' => 'upstream'], 500),
        ]);

        $service->checkTransaction('abc');
    }

    public function test_uses_preprovisioned_token_when_no_client_credentials(): void
    {
        config(['bakong.client_id' => '']);
        config(['bakong.client_secret' => '']);
        config(['bakong.token' => 'pre-provisioned-token']);

        Http::fake([
            '*/KHQR/generate' => function ($request) {
                $this->assertSame('Bearer pre-provisioned-token', $request->header('Authorization')[0] ?? '');
                return Http::response(['responseCode' => 0, 'data' => ['md5' => 'x', 'qr' => 'qr']], 200);
            },
        ]);

        $api = new BakongApi('https://api-bakong.nbc.gov.kh/v1', 30);
        $service = new BakongService($api);

        $result = $service->generateQr(5.00);
        $this->assertSame('x', $result['md5']);
    }
}
