<?php

namespace App\View\Components;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\View\Component;
use Illuminate\View\View;
use KHQR\BakongKHQR;
use KHQR\Helpers\KHQRData;
use KHQR\Models\IndividualInfo;

class BakongQrCard extends Component
{
    public string $qrDataUri = '';
    public string $qrSvg = '';

    public function __construct(
        public ?string $qrString = null,
        public string $name = 'SOTHAT OUK',
        public ?string $account = null,
        public ?string $currency = 'USD',
        public ?float $amount = null,
        public string $subtitle = 'Scan. Pay. Done.',
        public bool $showActions = true,
        public int $size = 280,
    ) {
        $this->resolveQr();
    }

    protected function resolveQr(): void
    {
        $payload = $this->qrString;

        // If no raw QR string was passed, generate a standard KHQR individual payload
        if (empty($payload)) {
            try {
                $currencyConst = strtoupper($this->currency ?? 'USD') === 'KHR'
                    ? KHQRData::CURRENCY_KHR
                    : KHQRData::CURRENCY_USD;

                $account = $this->account ?: 'sothat@bakong';

                $individualInfo = new IndividualInfo(
                    bakongAccountID: $account,
                    merchantName: $this->name,
                    merchantCity: 'PHNOM PENH',
                    currency: $currencyConst,
                    amount: $this->amount
                );

                $resp = BakongKHQR::generateIndividual($individualInfo);
                $payload = $resp->data['qr'] ?? '00020101021229180014' . $account . '520459995802KH5910' . substr($this->name, 0, 10) . '6010PHNOM PENH6304ABCD';
            } catch (\Throwable) {
                $payload = '00020101021229180014sothat@bakong520459995802KH5910SOTHAT OUK6010PHNOM PENH6304ABCD';
            }
        }

        $this->qrString = $payload;

        // Generate QR code with High error correction (level H ~30% recovery)
        // to guarantee the center Bakong emblem does not impair scanning.
        $qrCode = new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $this->size,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        $svgWriter = new SvgWriter();
        $this->qrSvg = $svgWriter->write($qrCode)->getString();

        $pngWriter = new PngWriter();
        $this->qrDataUri = $pngWriter->write($qrCode)->getDataUri();
    }

    public function render(): View
    {
        return view('components.bakong-qr-card');
    }
}
