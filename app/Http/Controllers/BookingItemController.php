<?php

namespace App\Http\Controllers;

use App\Models\BookingItem;
use Illuminate\Http\Request;

class BookingItemController extends Controller
{
    public function index (){
        return response()->json([
            'success' => true,
            'data' => BookingItem::with([
                'booking' ,
                'ticketType'
            ])->latest()->get()
        ]);
    }

    public function show($id){
        $item = BookingItem::with([
            'booking' ,
            'ticketType'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $item
        ]);
    }
}
