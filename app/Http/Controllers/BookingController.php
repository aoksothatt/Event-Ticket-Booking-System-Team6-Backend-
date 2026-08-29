<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class BookingController extends Controller
{
    public function index(){
        $bookings = Booking::with([
            'user',
            'event',
            'items.ticketType',
            'payments'
        ])->latest()->get();
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
                    abort(422, "Not enough tickets available.");
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
            'message' => 'Booking created successfully',
            'data' => $booking->load(
                'items.ticketType',
                'event',
                'user'
            )
        ], 201);
    }

    public function show($id){
        $booking = Booking::with([
            'user',
            'events',
            'items.ticketType',
            'payments',
            'checkIns'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $booking
        ]);
    }

    public function update(Request $request ,$id){
        $booking = Booking::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|max:30',
        ]);

        $booking->update($validated);

        return response()->json([
            'success' =>true,
            'message' => "Booking updated successfully",
            'data' => $booking
        ]);
    }

    public function destroy($id){
        $booking = Booking::findOrFail($id);

        $booking-> delete();

        return response()->json([
            'success' => true,
            'message' => 'Booking deleted successfully'
        ]);
    }
}
