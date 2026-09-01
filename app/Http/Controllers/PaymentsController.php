<?php

namespace App\Http\Controllers;

use App\Models\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class PaymentsController extends Controller
{
    public function index(){
        return response()->json([
            'success' => true,
            'data' => Payments::with('booking')->latest()->get()
        ]);
    }
    public function store(Request $request){
        $validated = $request->validate([
            'booking_id'=>'required|exists:Booking,id',
            'payment_method'=>'required|string|max:50',
            'amount' => 'required|numeric:max:0',
            'currency' => 'required|string|max:10',
            'payment_status' => 'required|string|max:30',
        ]);
        $validated['transaction_id'] = 'TXN-' .strtoupper(Str::random(12));

        if($validated['payment_status']==='paid'){
            $validated['paid_at'] = now();
        }

        $payment = Payments::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payment created successFully',
            'date' => $payment
        ],201);
    }

}
