<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\CheckInException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\CheckInService;
use App\Services\TicketExpirationService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(
        private readonly CheckInService $checkInService,
        private readonly TicketExpirationService $expiration,
    ) {}

    /**
     * The authenticated customer's active tickets (with their QR payloads).
     * Includes both DONE and ACTIVE tickets.
     */
    public function myTickets(Request $request)
    {
        $tickets = Ticket::query()
            ->with(['ticketType.event.venue', 'booking', 'event', 'ticketCheckins'])
            ->visibleToUser()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => TicketResource::collection($tickets),
        ]);
    }

    /**
     * The authenticated customer's historical (expired/cancelled/refunded)
     * tickets with their audit trail.
     */
    public function history(Request $request)
    {
        $tickets = Ticket::query()
            ->with(['ticketType.event.venue', 'booking', 'logs'])
            ->history()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => TicketResource::collection($tickets),
        ]);
    }

    /**
     * Self check-in via the customer's own QR code. The ticket MUST belong
     * to the authenticated customer and only works inside the event window.
     */
    public function selfCheckIn(Request $request)
    {
        $validated = $request->validate([
            'ticket_code' => ['required', 'string'],
        ]);

        $ticket = Ticket::query()
            ->where('user_id', $request->user()->id)
            ->where(fn ($q) => $q
                ->where('qr_token', $validated['ticket_code'])
                ->orWhere('ticket_code', $validated['ticket_code']))
            ->first();

        if ($ticket === null) {
            return response()->json([
                'success' => false,
                'message' => 'No matching ticket was found for your account.',
            ], 422);
        }

        // Self check-in is only permitted while the event is running.
        if (! $this->expiration->isInsideEventWindow($ticket)) {
            return response()->json([
                'success' => false,
                'message' => 'Check-in is only available during the event hours: '
                    .$this->expiration->eventWindowLabel($ticket).'.',
            ], 422);
        }

        try {
            $ticketCheckin = $this->checkInService->checkIn($ticket, $request->user(), [
                'device_name' => $request->input('device_name'),
                'ip_address' => $request->ip(),
                'source' => 'customer',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Check-in successful. Enjoy the event!',
                'data' => new TicketResource($ticket->fresh(['event', 'ticketType', 'user'])),
                'check_in' => $ticketCheckin,
            ]);
        } catch (CheckInException $e) {
            return response()->json([
                'success' => false,
                'status' => $e->status(),
                'message' => $e->getMessage(),
            ], 409);
        }
    }
}
