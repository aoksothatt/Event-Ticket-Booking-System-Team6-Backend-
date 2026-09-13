<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Bakong Member KHQR Stand Preview</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Kantumruy+Pro:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen py-10 px-4 sm:px-6">

    <div class="max-w-4xl mx-auto">
        {{-- Page Header --}}
        <div class="text-center mb-8 print:hidden">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-red-100 text-[#E1251B] text-xs font-bold uppercase tracking-wider mb-2">
                Official NBC Standard
            </span>
            <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">
                Bakong Member KHQR Stand
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                A reusable Laravel Blade component for merchant counter stands, ticketing, and POS.
            </p>
        </div>

        {{-- Interactive Stand Card Display --}}
        <div class="flex flex-col items-center justify-center">
            <x-bakong-qr-card
                :name="$name ?? 'SOTHAT OUK'"
                :qr-string="$qrString ?? null"
                :amount="$amount ?? null"
                :currency="$currency ?? 'USD'"
                :account="$account ?? 'sothat@bakong'"
            />
        </div>

        {{-- Code snippet usage guide --}}
        <div class="mt-12 bg-white rounded-2xl p-6 border border-gray-200 shadow-sm print:hidden">
            <h2 class="text-base font-bold text-gray-900 mb-2">Usage in any Blade template:</h2>
            <div class="bg-gray-900 text-gray-100 p-4 rounded-xl text-xs sm:text-sm font-mono overflow-x-auto">
&lt;!-- Basic Usage (Defaults to SOTHAT OUK) --&gt;
&lt;x-bakong-qr-card :qr-string="$qrString" name="SOTHAT OUK" /&gt;

&lt;!-- With Fixed Amount & Currency --&gt;
&lt;x-bakong-qr-card
    :qr-string="$payment->qr_payload"
    name="SOTHAT OUK"
    amount="25.00"
    currency="USD"
    account="sothat@bakong"
/&gt;
            </div>
        </div>
    </div>

</body>
</html>

