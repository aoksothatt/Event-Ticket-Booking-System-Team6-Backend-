<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function index (){
        return response()->json([
            'success' => true,
            'data' => CheckIn::with([
                'booking' ,
                'checkedBy'
            ])->latest()->get()
        ]);
    }

    public function store(Request $request){
        $validated = $request ->validate([
            'booking_id ' => 'required|exists:bookings,id',
            'checked_by' => 'required|exists:users,id',
            'status' => 'required|string|max:20',
        ]);

        $validated['checked_in_at'] = now();

        $checkIn = CheckIn::create($validated);

        return response()->json([
            'success' =>true,
            'message' => ' Check-in successfully',
            'data' => $checkIn->load([
                'booking' ,
                'checkedBy'
            ])
        ],201);
    }

    public function show($id){
        $checkIn = CheckIn::with([
            'booking' ,
            'checkedBy'

        ])->findOrFail($id);


        return response()->json([
            'success' => true,
            'data' => $checkIn
        ]);
    }

    public function update(Request $request ,$id){
        $checkIn = CheckIn::findOrFail($id);


        $validated = $request->validate([
                'status' => 'required|string|max:20',
            ]);


        $checkIn->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Check-in updated successfully',
            'data' => $checkIn
        ]);
    }
}
