<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentsController extends Controller
{
    /**
     * Payments index — scoped by role so a customer can never see another
     * customer's payment records:
     *   - customer  → only their own bookings' payments
     *   - organizer → only payments for their events
     *   - admin     → everything
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Payments::with(['booking.user', 'booking.event', 'booking.tickets'])->latest();

        if ($user->role === Role::CUSTOMER->value) {
            $query->whereHas('booking', fn($q) => $q->where('user_id', $user->id));
        } elseif ($user->role === Role::ORGANIZER->value && $user->organizerProfile) {
            $query->whereHas(
                'booking.event',
                fn($q) => $q->where('organizer_id', $user->organizerProfile->id)
            );
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    /**
     * Payments belonging to the authenticated customer (for their dashboard).
     */
    public function my(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => Payments::with(['booking.event'])
                ->whereHas('booking', fn($q) => $q->where('user_id', $request->user()->id))
                ->latest()
                ->get()
        ]);
    }

    /**
     * Record a pending payment for a booking.
     *
     * SECURITY: this endpoint is intentionally no longer able to mark a
     * payment as "paid" or confirm a booking — a payment may ONLY be marked
     * paid after the backend verifies the transaction with Bakong via
     * PaymentVerificationService (see PaymentController@verify / webhook).
     *
     * If the booking already has a settled or live pending payment, that
     * record is returned unchanged (idempotent) so repeated calls never
     * create duplicates.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:Booking,id',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $booking = Booking::with('items')->findOrFail($validated['booking_id']);

        // A customer may only create a payment for their own booking.
        if ($request->user('api')->role === Role::CUSTOMER->value && (int) $booking->user_id !== (int) $request->user('api')->id) {
            return response()->json([
                'success' => false,
                'message' => __('messages.cannot_pay_others_bookings'),
            ], 403);
        }

        $payment = Payments::where('booking_id', $booking->id)
            ->where('status', Payment::STATUS_PAID)
            ->latest('id')
            ->first();

        if ($payment) {
            Log::channel('bakong')->warning('PaymentsController: booked a payment for an already-settled booking.', [
                'booking_id' => $booking->id,
                'payment_id' => $payment->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'This booking is already paid.',
                'data' => $payment->load('booking', 'booking.tickets'),
            ]);
        }

        // Reuse a still-active pending payment instead of stacking duplicates.
        $payment = Payments::where('booking_id', $booking->id)
            ->where('status', Payment::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (! $payment) {
            $payment = Payments::create([
                'booking_id'           => $booking->id,
                'provider'             => Payment::PROVIDER_BAKONG,
                'payment_method'       => $validated['payment_method'] ?? 'bakong_khqr',
                'transaction_reference' => 'PAY-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'transaction_id'       => 'PAY-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'amount'               => $booking->total_amount,
                'currency'             => config('bakong.currency', 'USD'),
                'status'               => Payment::STATUS_PENDING,
                'payment_status'       => 'pending',
            ]);
        }

        Log::channel('bakong')->info('Legacy payment record created (pending).', [
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.payment_created'),
            'data' => $payment->load('booking', 'booking.tickets')
        ], 201);
    }
}
