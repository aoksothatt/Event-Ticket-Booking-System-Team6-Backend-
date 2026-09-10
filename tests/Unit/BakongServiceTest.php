<?php

namespace Tests\Unit;

use App\Services\Bakong\BakongApi;
use App\Services\Bakong\BakongException;
use App\Services\Bakong\BakongService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BakongServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['bakong.base_url' => 'https://api-bakong.nbc.gov.kh/v1']);
        config(['bakong.merchant_name' => 'EventBooking']);
        config(['bakong.merchant_city' => 'Phnom Penh']);
        config(['bakong.account_id' => 'merchant@bkrt']);
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

    public function test_generate_khqr_is_local_and_returns_md5_of_the_khqr(): void
    {
        // No Bakong HTTP calls are involved in KHQR generation.
        Http::fake();

        $service = $this->serviceWithFake([]);
        $result = $service->generateKhqr(25.50, 'TXN-LOCAL-001');

        $this->assertStringStartsWith('000201', $result['khqr']);
        $this->assertSame(32, strlen($result['md5']));
        $this->assertSame(md5($result['khqr']), $result['md5'], 'MD5 must be computed from the exact KHQR string.');
        $this->assertNotEmpty($result['expires_at']);
    }

    public function test_generate_khqr_throws_when_merchant_name_missing(): void
    {
        config(['bakong.merchant_name' => '']);

        $this->expectException(BakongException::class);
        $this->expectExceptionMessage('BAKONG_MERCHANT_NAME');

        $service = $this->serviceWithFake([]);
        $service->generateKhqr(10.00);
    }

    public function test_generate_deeplink_returns_short_link(): void
    {
        $service = $this->serviceWithFake([
            '*/generate_deeplink_by_qr' => Http::response([
                'responseCode' => 0,
                'responseMessage' => 'Getting deep link successfully',
                'data' => ['shortLink' => 'https://bakong.page.link/abc123'],
            ], 200),
        ]);

        $link = $service->generateDeeplink('000201010212khqrpayload');

        $this->assertSame('https://bakong.page.link/abc123', $link);
    }

    public function test_generate_deeplink_returns_null_when_response_code_not_zero(): void
    {
        $service = $this->serviceWithFake([
            '*/generate_deeplink_by_qr' => Http::response([
                'responseCode' => 1,
                'responseMessage' => 'Invalid QR',
                'data' => null,
            ], 200),
        ]);

        $this->assertNull($service->generateDeeplink('000201010212khqrpayload'));
    }

    public function test_generate_deeplink_returns_null_when_gateway_fails(): void
    {
        $service = $this->serviceWithFake([
            '*/generate_deeplink_by_qr' => Http::response(['error' => 'upstream'], 500),
        ]);

        $this->assertNull($service->generateDeeplink('000201010212khqrpayload'));
    }

    public function test_generate_deeplink_returns_null_for_blank_khqr(): void
    {
        $service = $this->serviceWithFake([]);

        $this->assertNull($service->generateDeeplink(''));
    }

    public function test_check_transaction_by_md5_maps_completed_to_paid(): void
    {
        $service = $this->serviceWithFake([
            '*/check_transaction_by_md5' => Http::response([
                'data' => ['status' => 'COMPLETED', 'bakong_transaction_id' => 'TXN-9-01'],
            ], 200),
        ]);

        $result = $service->checkTransactionByMd5('abc123');

        $this->assertSame('paid', $result['status']);
        $this->assertSame('TXN-9-01', $result['transaction_id']);
    }

    public function test_check_transaction_by_md5_maps_failed(): void
    {
        $service = $this->serviceWithFake([
            '*/check_transaction_by_md5' => Http::response(['data' => ['status' => 'FAILED']], 200),
        ]);

        $this->assertSame('failed', $service->checkTransactionByMd5('abc')['status']);
    }

    public function test_check_transaction_by_md5_maps_pending(): void
    {
        $service = $this->serviceWithFake([
            '*/check_transaction_by_md5' => Http::response(['data' => ['status' => 'PENDING']], 200),
        ]);

        $this->assertSame('pending', $service->checkTransactionByMd5('abc')['status']);
    }

    public function test_check_transaction_by_md5_throws_on_http_error(): void
    {
        $this->expectException(BakongException::class);

        $service = $this->serviceWithFake([
            '*/check_transaction_by_md5' => Http::response(['error' => 'upstream'], 500),
        ]);

        $service->checkTransactionByMd5('abc');
    }

    public function test_uses_preprovisioned_token_for_transaction_check(): void
    {
        config(['bakong.client_id' => '']);
        config(['bakong.client_secret' => '']);
        config(['bakong.token' => 'pre-provisioned-token']);

        Http::fake([
            '*/check_transaction_by_md5' => function ($request) {
                $this->assertSame('Bearer pre-provisioned-token', $request->header('Authorization')[0] ?? '');

                return Http::response(['data' => ['status' => 'COMPLETED']], 200);
            },
        ]);

        $api = new BakongApi('https://api-bakong.nbc.gov.kh/v1', 30);
        $service = new BakongService($api);

        $this->assertSame('paid', $service->checkTransactionByMd5('abc')['status']);
    }
}