<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    /**
     * List actual customer tickets (admin/organizer view).
     */
    public function index(Request $request)
    {
        $tickets = Ticket::with([
            'user',
            'booking',
            'ticketType.event',
            'bookingItem',
        ])
            ->when($request->search, function ($q, $search) {
                $q->where('ticket_code', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('ticketType.event', fn ($e) => $e->where('title', 'like', "%{$search}%"));
            })
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    /**
     * Show a single ticket.
     */
    public function show($id)
    {
        $ticket = Ticket::with([
            'user',
            'booking',
            'ticketType.event',
            'bookingItem',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $ticket,
        ]);
    }

    /**
     * The currently authenticated customer's actual tickets.
     */
    public function myTickets(Request $request)
    {
        $tickets = Ticket::with([
            'ticketType.event.venue',
            'ticketType.event', // ensure event present
            'booking',
        ])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    /**
     * Verify/check in a ticket by its secure QR token.
     * Marks the ticket as used and records a check-in.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'qr_token' => 'required|string',
        ]);

        $ticket = Ticket::with(['ticketType.event', 'user', 'booking'])
            ->where('qr_token', $validated['qr_token'])
            ->first();

        if (! $ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid ticket. Please check the QR code and try again.',
            ], 422);
        }

        if ($ticket->status === 'used') {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has already been used.',
            ], 422);
        }

        if ($ticket->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'This ticket is not active and cannot be checked in.',
            ], 422);
        }

        $result = DB::transaction(function () use ($ticket, $request) {
            $ticket->update([
                'status' => 'used',
                'used_at' => now(),
            ]);

            $checkIn = CheckIn::create([
                'booking_id'    => $ticket->booking_id,
                'ticket_id'     => $ticket->id,
                'checked_by'    => $request->user()->id,
                'checked_in_at' => now(),
                'status'        => 'checked_in',
            ]);

            return $checkIn;
        });

        return response()->json([
            'success' => true,
            'message' => 'Ticket verified and checked in successfully.',
            'data' => $ticket->fresh(['ticketType.event', 'user', 'booking']),
            'check_in' => $result,
        ], 200);
    }

    /**
     * Admin cancels an actual ticket if business rules allow.
     */
    public function cancel($id)
    {
        $ticket = Ticket::findOrFail($id);

        if ($ticket->status === 'used') {
            return response()->json([
                'success' => false,
                'message' => 'A used ticket cannot be cancelled.',
            ], 422);
        }

        $ticket->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Ticket cancelled successfully.',
            'data' => $ticket,
        ]);
    }
}