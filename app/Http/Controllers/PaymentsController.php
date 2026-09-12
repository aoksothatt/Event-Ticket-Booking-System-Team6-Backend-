<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payments;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class PaymentsController extends Controller
{
    public function index(){
        return response()->json([
            'success' => true,
            'data' => Payments::with(['booking.user', 'booking.event', 'booking.tickets'])->latest()->get()
        ]);
    }

    /**
     * Payments belonging to the authenticated customer (for their dashboard).
     */
    public function my(Request $request){
        return response()->json([
            'success' => true,
            'data' => Payments::with(['booking.event'])
                ->whereHas('booking', fn ($q) => $q->where('user_id', $request->user()->id))
                ->latest()
                ->get()
        ]);
    }
    public function store(Request $request){
        $validated = $request->validate([
            'booking_id'=>'required|exists:Booking,id',
            'payment_method'=>'required|string|max:50',
            'amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'payment_status' => 'nullable|string|max:30',
            'transaction_id' => 'nullable|string|max:150',
        ]);

        $booking = Booking::with('items')->findOrFail($validated['booking_id']);

        // A customer may only create a payment for their own booking.
        if ($request->user('api')->role === 'customer' && $booking->user_id !== $request->user('api')->id) {
            return response()->json([
                'success' => false,
                'message' => __('messages.cannot_pay_others_bookings'),
            ], 403);
        }

        // Use the authoritative amount from the booking, ignoring any
        // client-supplied amount unless it is explicitly given.
        $amount = $validated['amount'] ?? $booking->total_amount;
        $currency = $validated['currency'] ?? 'USD';
        $paymentStatus = $validated['payment_status'] ?? 'paid';

        $payment = DB::transaction(function () use ($validated, $booking, $amount, $currency, $paymentStatus, $request) {
            // Guard against duplicate payments being recorded for the same booking
            // + transaction (idempotency when a gateway confirmation fires twice).
            $existing = Payments::where('booking_id', $booking->id)
                ->where('transaction_id', $validated['transaction_id'] ?? '')
                ->first();

            $transactionId = $validated['transaction_id']
                ?? ($existing ? $existing->transaction_id : 'TXN-' . strtoupper(Str::random(12)));

            if ($existing) {
                return $existing;
            }

            $payment = Payments::create([
                'booking_id'      => $booking->id,
                'payment_method'  => $validated['payment_method'],
                'transaction_id'  => $transactionId,
                'amount'          => $amount,
                'currency'        => $currency,
                'payment_status'  => $paymentStatus,
                'paid_at'         => $paymentStatus === 'paid' ? now() : null,
            ]);

            // Only confirm the booking + generate tickets once payment succeeds.
            if ($paymentStatus === 'paid' && $booking->status !== 'confirmed') {
                $booking->update(['status' => 'confirmed']);

                app(TicketService::class)->generateForBooking($booking);
            }

            return $payment;
        });

        return response()->json([
            'success' => true,
            'message' => __('messages.payment_created'),
            'data' => $payment->load('booking', 'booking.tickets')
        ], 201);
    }

}
