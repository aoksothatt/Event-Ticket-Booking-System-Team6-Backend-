<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => CheckIn::with([
                'booking.user',
                'booking.event',
                'ticket.ticketType.event',
                'ticket.user',
                'checkedBy'
            ])->latest()->get()
        ]);
    }

    /**
     * Check-ins belonging to the authenticated customer (for their dashboard).
     */
    public function my(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => CheckIn::with([
                'booking.event',
                'ticket.ticketType.event',
            ])
                ->whereHas('booking', fn ($q) => $q->where('user_id', $request->user()->id))
                ->latest()
                ->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:Booking,id',
            'ticket_id' => 'nullable|exists:tickets,id',
            'status' => 'required|string|max:20',
        ]);

        // The actor who performs the check-in is always the authenticated
        // user (admin/organizer/scanner), never a value from the client.
        $checkIn = CheckIn::create([
            'booking_id' => $validated['booking_id'],
            'ticket_id' => $validated['ticket_id'] ?? null,
            'checked_by' => $request->user()->id,
            'checked_in_at' => now(),
            'status' => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.checkin_created'),
            'data' => $checkIn->load([
                'booking',
                'ticket',
                'checkedBy'
            ])
        ], 201);
    }

    public function show($id)
    {
        $checkIn = CheckIn::with([
            'booking',
            'checkedBy'

        ])->findOrFail($id);


        return response()->json([
            'success' => true,
            'data' => $checkIn
        ]);
    }

    public function update(Request $request, $id)
    {
        $checkIn = CheckIn::findOrFail($id);


        $validated = $request->validate([
            'status' => 'required|string|max:20',
        ]);


        $checkIn->update($validated);

        return response()->json([
            'success' => true,
            'message' => __('messages.checkin_updated'),
            'data' => $checkIn
        ]);
    }
}

