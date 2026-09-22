<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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

        // QR codes disabled at the platform level: hide every qr_token.
        if (! Setting::value('ticket.qr_enabled', true)) {
            $tickets->getCollection()->each->makeHidden('qr_token');
        }

        return response()->json([
            'success' => true,
            'message' => 'My active tickets retrieved successfully.',
            'data' => $tickets,
        ]);
    }
}
