<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tickets = $request->user()
            ->tickets()
            ->visibleToUser()
            ->with([
                'event',
                'bookingItem.ticketType',
            ])
            ->latest()
            ->paginate(
                $request->integer('per_page', 10)
            );

        return response()->json([
            'success' => true,
            'message' => 'My active tickets retrieved successfully.',
            'data' => $tickets,
        ]);
    }
}
