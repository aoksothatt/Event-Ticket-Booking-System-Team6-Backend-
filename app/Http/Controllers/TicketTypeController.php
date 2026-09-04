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
            'message' => "get all ticket",
            'status' => true,
            'data' => $ticketType
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
            'message' => "ticket create !!",
            'status' => true,
            'data' => $ticketType
        ], 200);
    }

    public function show($id)
    {
        $ticketType = TicketType::findOrFail($id);

        if (!$ticketType) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket type not found!',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Ticket type retrieved successfully!',
            'data' => $ticketType,
        ], 200);
    }


    public function update(Request $request, $id)
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

        $ticketType = TicketType::findOrFail($id);

        $ticketType->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Ticket type Update successfully!',
            'data' => $ticketType,
        ], 200);
    }

    public function destroy($id)
    {
        $ticketType = TicketType::findOrFail($id);

        $ticketType->delete();
        return response()->json([
            'status' => true,
            'message' => 'Ticket type deleted!',
            'data' => $ticketType,
        ], 200);
    }
}
