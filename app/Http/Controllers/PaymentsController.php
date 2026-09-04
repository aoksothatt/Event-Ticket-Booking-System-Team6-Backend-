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
    public function store(Request $request){
        $validated = $request->validate([
            'booking_id'=>'required|exists:Booking,id',
            'payment_method'=>'required|string|max:50',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|max:10',
            'payment_status' => 'required|string|max:30',
        ]);

        $booking = Booking::with('items')->findOrFail($validated['booking_id']);

        $payment = DB::transaction(function () use ($validated, $booking) {
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
                'amount'          => $validated['amount'],
                'currency'        => $validated['currency'],
                'payment_status'  => $validated['payment_status'],
                'paid_at'         => $validated['payment_status'] === 'paid' ? now() : null,
            ]);

            // Only confirm the booking + generate tickets once payment succeeds.
            if ($validated['payment_status'] === 'paid' && $booking->status !== 'confirmed') {
                $booking->update(['status' => 'confirmed']);

                app(TicketService::class)->generateForBooking($booking);
            }

            return $payment;
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment created successfully',
            'data' => $payment->load('booking', 'booking.tickets')
        ], 201);
    }

}
