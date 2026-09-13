<?php

namespace App\Http\Controllers;

use App\Models\TicketType;
use Illuminate\Http\Request;

class TicketTypeController extends Controller
{
    //

    public function index()
    {
        $ticketType = TicketType::with('event')->get();

        return response()->json([
            'message' => __('messages.ticket_type_retrieved'),
            'status' => true,
            'data' => $ticketType,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|exists:events,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:1',
            'sold_quantity' => 'nullable|integer|min:0|lte:quantity',
            'status' => 'required|in:active,inactive,sold_out',
        ]);

        $ticketType = TicketType::create($validated);

        return response()->json([
            'message' => __('messages.ticket_type_created'),
            'status' => true,
            'data' => $ticketType,
        ], 200);
    }

    public function show($id)
    {
        $ticketType = TicketType::findOrFail($id);

        if (! $ticketType) {
            return response()->json([
                'status' => false,
                'message' => __('messages.ticket_type_not_found'),
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => __('messages.ticket_type_retrieved'),
            'data' => $ticketType,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            // Optional on updates: when the client omits it, the ticket type
            // keeps its existing event (PATCH-style semantics).
            'event_id' => 'sometimes|required|exists:events,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:1',
            'sold_quantity' => 'nullable|integer|min:0|lte:quantity',
            'status' => 'required|in:active,inactive,sold_out',
        ]);

        $ticketType = TicketType::findOrFail($id);

        $ticketType->update($validated);

        return response()->json([
            'status' => true,
            'message' => __('messages.ticket_type_updated'),
            'data' => $ticketType,
        ], 200);
    }

    public function destroy($id)
    {
        $ticketType = TicketType::findOrFail($id);

        // Issued customer tickets reference this ticket type, so a hard delete
        // would violate the FK (tickets.ticket_type_id). Refuse with a clear
        // message instead of a raw SQLSTATE 500, and steer the operator to
        // deactivate the ticket type instead.
        $ticketCount = $ticketType->tickets()->count();
        if ($ticketCount > 0) {
            return response()->json([
                'status' => false,
                'message' => __('messages.ticket_type_has_issued_tickets', ['count' => $ticketCount]),
            ], 409);
        }

        // Also guard against ticket-type references from booking items.
        if ($ticketType->bookingItems()->exists()) {
            return response()->json([
                'status' => false,
                'message' => __('messages.ticket_type_referenced_by_bookings'),
            ], 409);
        }

        $ticketType->delete();

        return response()->json([
            'status' => true,
            'message' => __('messages.ticket_type_deleted'),
            'data' => $ticketType,
        ], 200);
    }

    /**
     * Toggle a ticket type's availability without needing the whole record.
     * Used when a ticket type has issued tickets and cannot be deleted:
     * the operator can set it to inactive / sold_out so it stops accepting
     * new purchases while already-issued tickets stay valid.
     */
    public function setStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,sold_out',
        ]);

        $ticketType = TicketType::findOrFail($id);
        $ticketType->update(['status' => $validated['status']]);

        return response()->json([
            'status' => true,
            'message' => __('messages.ticket_type_status_updated', ['status' => $validated['status']]),
            'data' => $ticketType->fresh(),
        ], 200);
    }
}
