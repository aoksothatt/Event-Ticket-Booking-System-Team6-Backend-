@props([
    'name' => 'SOTHAT OUK',
    'qrString' => null,
    'subtitle' => 'Scan. Pay. Done.',
    'currency' => 'USD',
    'amount' => null,
    'account' => null,
    'showActions' => true,
    'cardId' => 'bakong-stand-card-' . uniqid(),
])

@php
    $formattedAmount = $amount !== null ? number_format((float) $amount, 2) : null;
@endphp

<div class="bakong-qr-wrapper inline-block font-sans text-gray-900 select-none">
    {{-- Main KHQR Stand Card --}}
    <div id="{{ $cardId }}"
         class="bakong-stand-canvas relative w-[360px] sm:w-[410px] aspect-[3/4] bg-[#FBFBFA] rounded-[28px] shadow-2xl border border-gray-200/90 overflow-hidden flex flex-col justify-between p-6 sm:p-7 print:shadow-none print:border-none print:m-0 print:w-full print:max-w-[420px]"
         style="background-color: #FBFBFA; background-image: radial-gradient(circle at 50% 50%, rgba(225, 37, 27, 0.015) 0%, transparent 60%), repeating-linear-gradient(45deg, rgba(0,0,0,0.018) 0px, rgba(0,0,0,0.018) 2px, transparent 2px, transparent 12px);">

        {{-- Top Red Accent Strip --}}
        <div class="absolute top-0 left-0 right-0 h-2 bg-[#E1251B] z-10"></div>

        {{-- Watermark Background Embellishment --}}
        <div class="absolute inset-0 pointer-events-none opacity-[0.035] flex items-center justify-center overflow-hidden" aria-hidden="true">
            <svg class="w-[500px] h-[500px] text-[#E1251B]" viewBox="0 0 100 100" fill="currentColor">
                <path d="M50 0 C55 25 75 45 100 50 C75 55 55 75 50 100 C45 75 25 55 0 50 C25 45 45 25 50 0 Z" />
                <circle cx="50" cy="50" r="22" fill="none" stroke="currentColor" stroke-width="2"/>
                <circle cx="50" cy="50" r="14" fill="none" stroke="currentColor" stroke-width="1.5"/>
            </svg>
        </div>

        {{-- ==================== 1. HEADER SECTION ==================== --}}
        <header class="relative z-10 text-center pt-2">
            {{-- Official Red NBC Bakong Flower Logo + Typography --}}
            <div class="flex items-center justify-center gap-2.5">
                {{-- Stylized Red Lotus Swirl Logo --}}
                <svg class="w-9 h-9 text-[#E1251B] flex-shrink-0 drop-shadow-sm" viewBox="0 0 48 48" fill="currentColor">
                    <circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="2.5" opacity="0.2"/>
                    <path d="M24 6 C25 14 31 20 39 21 C31 22 25 28 24 36 C23 28 17 22 9 21 C17 20 23 14 24 6 Z" />
                    <circle cx="24" cy="24" r="5.5" fill="#FFFFFF"/>
                    <circle cx="24" cy="24" r="3.2" fill="#E1251B"/>
                    <path d="M24 11 C26 17 31 22 37 24 C31 26 26 31 24 37 C22 31 17 26 11 24 C17 22 22 17 24 11 Z" opacity="0.6" fill="#FFFFFF"/>
                </svg>

                <div class="text-left leading-none">
                    {{-- Khmer Text "បាកតង" / "បាគង" --}}
                    <div class="text-[#E1251B] font-bold text-sm tracking-wide font-serif">បាកតង</div>
                    {{-- English Text "BAKONG" --}}
                    <div class="text-[#E1251B] font-black text-xl tracking-wider font-sans">BAKONG</div>
                </div>
            </div>

            {{-- Subtitle --}}
            <p class="mt-2 text-xs sm:text-[13px] font-medium tracking-widest text-[#4B5563] uppercase">
                {{ $subtitle }}
            </p>
        </header>

        {{-- ==================== 2. QR CODE FRAMING ==================== --}}
        <section class="relative z-10 my-auto flex flex-col items-center justify-center">
            {{-- QR Container with Target Corners --}}
            <div class="relative p-3 bg-white rounded-2xl shadow-md border border-gray-100">

                {{-- Viewfinder Target Corners (Grey Rounded Brackets) --}}
                <div class="absolute -top-1.5 -left-1.5 w-6 h-6 border-t-[3.5px] border-l-[3.5px] border-[#94A3B8] rounded-tl-xl pointer-events-none"></div>
                <div class="absolute -top-1.5 -right-1.5 w-6 h-6 border-t-[3.5px] border-r-[3.5px] border-[#94A3B8] rounded-tr-xl pointer-events-none"></div>
                <div class="absolute -bottom-1.5 -left-1.5 w-6 h-6 border-b-[3.5px] border-l-[3.5px] border-[#94A3B8] rounded-bl-xl pointer-events-none"></div>
                <div class="absolute -bottom-1.5 -right-1.5 w-6 h-6 border-b-[3.5px] border-r-[3.5px] border-[#94A3B8] rounded-br-xl pointer-events-none"></div>

                {{-- QR Matrix Canvas / Image --}}
                <div class="relative w-[210px] h-[210px] sm:w-[240px] sm:h-[240px] flex items-center justify-center overflow-hidden bg-white">
                    <img src="{{ $qrDataUri }}"
                         alt="Bakong KHQR Code"
                         class="w-full h-full object-contain select-all" />

                    {{-- Center Red Bakong Emblem Overlay (Strict Error Correction H Guarantee) --}}
                    <div class="absolute inset-0 m-auto w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-[#E1251B] border-[3px] border-white shadow-md flex items-center justify-center pointer-events-none z-10"
                         title="Bakong Official KHQR">
                        <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2 C12.6 6.5 16 9.9 20.5 10.5 C16 11.1 12.6 14.5 12 19 C11.4 14.5 8 11.1 3.5 10.5 C8 9.9 11.4 6.5 12 2 Z"/>
                            <circle cx="12" cy="10.5" r="2.5" fill="#FFFFFF"/>
                            <circle cx="12" cy="10.5" r="1.4" fill="#E1251B"/>
                        </svg>
                    </div>
                </div>
            </div>

            {{-- ==================== 3. ACCOUNT DETAILS ==================== --}}
            <div class="mt-4 text-center">
                {{-- Account Name: Large, Bold, Uppercase Black Text --}}
                <h1 class="text-xl sm:text-2xl font-black tracking-wide text-gray-950 uppercase px-2 leading-tight">
                    {{ $name }}
                </h1>

                {{-- Optional Amount Display if provided --}}
                @if ($formattedAmount)
                    <div class="mt-1.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#E1251B]/10 border border-[#E1251B]/20 text-[#E1251B] font-bold text-sm sm:text-base">
                        <span>{{ $formattedAmount }}</span>
                        <span class="text-xs uppercase font-extrabold">{{ $currency }}</span>
                    </div>
                @elseif ($account)
                    <div class="mt-1 text-xs font-semibold text-gray-500 tracking-wider">
                        {{ $account }}
                    </div>
                @endif
            </div>
        </section>

        {{-- ==================== 4. FOOTER SECTION ==================== --}}
        <footer class="relative z-10 pt-3 pb-1 border-t border-gray-200/70">
            <div class="flex items-end justify-between gap-3">
                {{-- Bottom Left: "Member of" + Red KHQR Stylized Logo --}}
                <div class="flex flex-col items-start gap-1">
                    <span class="text-[9px] sm:text-[10px] font-bold uppercase tracking-widest text-gray-400 leading-none">
                        Member of
                    </span>
                    <div class="flex items-center gap-1">
                        {{-- Stylized KHQR Red Tag --}}
                        <div class="bg-[#E1251B] text-white px-2 py-0.5 rounded-[4px] font-black text-[13px] tracking-tight leading-tight flex items-center gap-0.5 shadow-sm">
                            <span class="tracking-tighter">KH</span><span class="font-extrabold text-[12px] bg-white text-[#E1251B] px-0.5 rounded-sm ml-0.5">QR</span>
                        </div>
                    </div>
                </div>

                {{-- Bottom Center: "Accepted here" + Brand Icons --}}
                <div class="flex flex-col items-center text-center">
                    <span class="text-[9px] sm:text-[10px] font-semibold text-gray-500 uppercase tracking-wider leading-none mb-1.5">
                        Accepted here
                    </span>
                    <div class="flex items-center gap-2 sm:gap-2.5">
                        {{-- UnionPay Icon --}}
                        <div class="h-5 sm:h-6 px-1.5 py-0.5 bg-white rounded border border-gray-200 flex items-center justify-center shadow-2xs" title="UnionPay">
                            <div class="flex items-center -space-x-1">
                                <span class="w-2.5 h-3.5 bg-[#E21818] rounded-xs transform -skew-x-12"></span>
                                <span class="w-2.5 h-3.5 bg-[#004B87] rounded-xs transform -skew-x-12"></span>
                                <span class="w-2.5 h-3.5 bg-[#007A3D] rounded-xs transform -skew-x-12"></span>
                            </div>
                            <span class="ml-1 text-[8px] sm:text-[9px] font-extrabold text-[#004B87] tracking-tighter">UnionPay</span>
                        </div>

                        {{-- WeChat Pay Icon --}}
                        <div class="h-5 sm:h-6 px-1.5 py-0.5 bg-white rounded border border-gray-200 flex items-center justify-center gap-1 shadow-2xs" title="WeChat Pay">
                            <svg class="w-3.5 h-3.5 text-[#07C160]" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9.5 4C5.36 4 2 6.91 2 10.5c0 2.05 1.08 3.87 2.78 5.04l-.7 2.1 2.45-1.22c.92.35 1.92.58 2.97.58.21 0 .42-.01.62-.03-.23-.62-.36-1.29-.36-1.97 0-3.31 3.13-6 7-6 .18 0 .36.01.54.02C16.32 6.27 13.18 4 9.5 4zm-2.25 4a1.25 1.25 0 110 2.5 1.25 1.25 0 010-2.5zm4.5 0a1.25 1.25 0 110 2.5 1.25 1.25 0 010-2.5zM16.5 10c-3.59 0-6.5 2.46-6.5 5.5 0 1.74.96 3.28 2.45 4.28l-.58 1.72 2.04-.98c.81.31 1.69.48 2.59.48 3.59 0 6.5-2.46 6.5-5.5S20.09 10 16.5 10zm-2 3.5a1 1 0 110 2 1 1 0 010-2zm4 0a1 1 0 110 2 1 1 0 010-2z"/>
                            </svg>
                            <span class="text-[8px] sm:text-[9px] font-bold text-gray-700">WeChat</span>
                        </div>

                        {{-- Alipay+ Icon --}}
                        <div class="h-5 sm:h-6 px-1.5 py-0.5 bg-white rounded border border-gray-200 flex items-center justify-center gap-0.5 shadow-2xs" title="Alipay+">
                            <div class="w-3.5 h-3.5 rounded bg-[#1677FF] flex items-center justify-center text-white font-bold text-[9px] leading-none">
                                支
                            </div>
                            <span class="text-[8px] sm:text-[9px] font-black text-[#1677FF] tracking-tighter">Alipay+</span>
                        </div>
                    </div>
                </div>

                {{-- Spacer to balance footer layout with the decorative corner graphic --}}
                <div class="w-12 sm:w-14"></div>
            </div>
        </footer>

        {{-- Bottom-Right Bold Red Rounded Corner Graphic --}}
        <div class="absolute bottom-0 right-0 w-14 h-14 sm:w-16 sm:h-16 bg-[#E1251B] rounded-tl-[42px] pointer-events-none z-10 shadow-inner"
             style="clip-path: polygon(0 100%, 100% 0, 100% 100%);">
        </div>

        {{-- Bottom Solid Red Border Strip --}}
        <div class="absolute bottom-0 left-0 right-0 h-2 sm:h-2.5 bg-[#E1251B] z-10"></div>
    </div>

    {{-- Optional Action Toolbar (Print / Download PNG) --}}
    @if ($showActions)
        <div class="mt-4 flex items-center justify-center gap-3 print:hidden">
            {{-- Print Button --}}
            <button type="button"
                    onclick="printBakongStand('{{ $cardId }}')"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50 hover:text-gray-900 shadow-sm transition active:scale-95">
                <svg class="w-4 h-4 text-[#E1251B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Print Stand / PDF
            </button>

            {{-- Download PNG Button --}}
            <button type="button"
                    onclick="downloadBakongStandPng('{{ $cardId }}', '{{ Str::slug($name) }}')"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-[#E1251B] rounded-xl hover:bg-[#C81E15] shadow-sm transition active:scale-95">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Download Image
            </button>
        </div>
    @endif
</div>

{{-- Stand Print and Canvas Download Script Helper (Included once) --}}
@once
<style>
    @media print {
        body * {
            visibility: hidden;
        }
        .bakong-stand-canvas, .bakong-stand-canvas * {
            visibility: visible !important;
        }
        .bakong-stand-canvas {
            position: absolute !important;
            left: 50% !important;
            top: 50% !important;
            transform: translate(-50%, -50%) !important;
            box-shadow: none !important;
            border: 1px solid #E5E7EB !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    }
</style>

<script>
    function printBakongStand(cardId) {
        window.print();
    }

    async function downloadBakongStandPng(cardId, slug) {
        const el = document.getElementById(cardId);
        if (!el) return;

        // Lazy load html2canvas if not already loaded
        if (typeof window.html2canvas === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
            script.onload = () => captureAndDownload(el, slug);
            document.head.appendChild(script);
        } else {
            captureAndDownload(el, slug);
        }
    }

    function captureAndDownload(el, slug) {
        window.html2canvas(el, {
            scale: 3, // High-res 300 DPI export
            useCORS: true,
            backgroundColor: '#FBFBFA',
            logging: false,
        }).then(canvas => {
            const link = document.createElement('a');
            link.download = 'bakong-khqr-stand-' + (slug || 'stand') + '.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    }
</script>
@endonce

