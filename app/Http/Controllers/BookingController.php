<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Event;
use App\Models\Setting;
use App\Models\TicketType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class BookingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $bookings = Booking::with([
            'user',
            'event',
            'event.venue',
            'items.ticketType',
            'paymentRecords',
            'tickets.ticketType',
        ])
            ->when($user->role === 'customer', fn ($q) => $q->where('user_id', $user->id))
            ->when($user->role === 'organizer', function ($q) use ($user) {
                $organizerId = $user->organizerProfile?->id;
                $q->whereHas('event', fn ($eq) => $eq->where('organizer_id', $organizerId));
            })
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings
        ]);
    }

    public function store(Request $request){
        $validated = $request->validate([
            'user_id' =>'required|exists:users,id',
            'event_id' =>'required|exists:events,id',

            'items' =>'required|array|min:1',

            'items.*.ticket_type_id' => [
                'required',
                'exists:ticket_types,id'
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1'
            ]
        ]);

        $booking = DB::transaction(function () use ($validated){
            $totalAmount = 0;
            foreach($validated['items'] as $item){
                $ticket = TicketType::lockForUpdate()->findOrFail($item['ticket_type_id']);

                $available = $ticket->quantity - $ticket->sold_quantity;

                if($item['quantity'] > $available){
                    abort(422, __('messages.not_enough_tickets'));
                }

                $totalAmount += $ticket->price * $item['quantity'];
            }
            $booking = Booking::create([
                'booking_number' => 'BK-' .strtoupper(Str::random(10)),
                'user_id' => $validated['user_id'],
                'event_id' => $validated['event_id'],
                'booking_date' =>now(),
                'total_amount' => $totalAmount,
                'status' => 'pending',
            ]);
            foreach ($validated['items'] as $item) {

                $ticket = TicketType::lockForUpdate()
                    ->findOrFail($item['ticket_type_id']);

                $subtotal =
                    $ticket->price * $item['quantity'];

                BookingItem::create([
                    'booking_id' => $booking->id,
                    'ticket_type_id' => $ticket->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $ticket->price,
                    'subtotal' => $subtotal,
                ]);

                $ticket->increment(
                    'sold_quantity',
                    $item['quantity']
                );
            }

            return $booking;
        });
        return response()->json([
            'success' => true,
            'message' => __('messages.booking_created'),
            'data' => $booking->load(
                'items.ticketType',
                'event',
                'user',
                'tickets'
            )
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $booking = Booking::with([
            'user',
            'event',
            'event.venue',
            'items.ticketType',
            'paymentRecords',
            'checkIns',
            'tickets.ticketType',
        ])->findOrFail($id);

        if ($user->role === 'customer' && (int) $booking->user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => __('messages.forbidden'),
            ], 403);
        }

        if ($user->role === 'organizer' && $booking->event !== null && (int) $booking->event->organizer_id !== (int) $user->organizerProfile?->id) {
            return response()->json([
                'success' => false,
                'message' => __('messages.forbidden'),
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $booking
        ]);
    }

    public function update(Request $request, $id){
        $booking = Booking::with('event')->findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'status' => 'required|string|max:30',
        ]);

        $oldStatus = $booking->status;
        $newStatus = $validated['status'];

        // Non-admins may only cancel bookings (admins keep full status
        // control). This also prevents a customer flipping arbitrary statuses.
        if ($user->role !== 'admin' && $newStatus !== 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => __('messages.forbidden'),
            ], 403);
        }

        // Ownership checks: customers only their own bookings, organizers only
        // bookings for events they own.
        if ($user->role === 'customer' && (int) $booking->user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => __('messages.forbidden'),
            ], 403);
        }

        if ($user->role === 'organizer' && $booking->event !== null
            && (int) $booking->event->organizer_id !== (int) $user->organizerProfile?->id) {
            return response()->json([
                'success' => false,
                'message' => __('messages.forbidden'),
            ], 403);
        }

        // Cancellation is governed by the platform settings: a global switch
        // (booking.cancellation_enabled) and a window measured in hours before
        // the event starts (booking.cancellation_deadline).
        if ($newStatus === 'cancelled' && $user->role !== 'admin') {
            if (! Setting::value('booking.cancellation_enabled', true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking cancellation is currently disabled on this platform.',
                ], 403);
            }

            $deadlineHours = (int) Setting::value('booking.cancellation_deadline', 24);
            $start = $booking->event ? $this->eventStartTimestamp($booking->event) : null;
            if ($start !== null && $deadlineHours > 0 && now()->gte($start->subHours($deadlineHours))) {
                return response()->json([
                    'success' => false,
                    'message' => "Cancellations close {$deadlineHours} hour(s) before the event starts.",
                ], 422);
            }
        }

        DB::transaction(function () use ($booking, $oldStatus, $newStatus, $validated) {
            // When a booking moves away from pending/confirmed to a cancelled
            // state, release the reserved inventory back to the ticket types.
            $restoreInventory = in_array($oldStatus, ['pending', 'confirmed'], true)
                && in_array($newStatus, ['cancelled', 'rejected', 'failed'], true);

            if ($restoreInventory) {
                foreach ($booking->items as $item) {
                    $item->ticketType()->increment('sold_quantity', -$item->quantity);
                }
            }

            $booking->update($validated);
        });

        return response()->json([
            'success' =>true,
            'message' => __('messages.booking_updated'),
            'data' => $booking->fresh(['items.ticketType'])
        ]);
    }

    /**
     * Build a Carbon timestamp from an event's start_date + start_time, or null
     * when the event has no scheduled start time (deadline check then skipped).
     */
    protected function eventStartTimestamp(Event $event): ?Carbon
    {
        if (! $event->start_date || ! $event->start_time) {
            return null;
        }

        return Carbon::parse($event->start_date->toDateString())
            ->setTimeFromTimeString((string) $event->start_time);
    }

    public function destroy($id){
        $booking = Booking::findOrFail($id);

        $booking-> delete();

        return response()->json([
            'success' => true,
            'message' => __('messages.booking_deleted')
        ]);
    }
}
