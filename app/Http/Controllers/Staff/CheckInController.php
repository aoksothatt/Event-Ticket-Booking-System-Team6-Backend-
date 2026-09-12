<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
use App\Exceptions\CheckInException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckIn\CheckInRequest;
use App\Http\Resources\CheckInResource;
use App\Http\Resources\TicketResource;
use App\Models\TicketCheckin;
use App\Services\CheckInService;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function __construct(
        private readonly CheckInService $checkInService,
    ) {}

    /**
     * Step 1 — look up a ticket without mutating it, so the scanner can show
     * ticket details before the operator confirms the check-in.
     */
    public function lookup(CheckInRequest $request)
    {
        $ticket = $this->checkInService->resolve($request->validated('ticket_code'));

        if ($ticket === null) {
            return response()->json([
                'success' => false,
                'status' => 'not_found',
                'message' => 'Invalid ticket. Please check the QR code and try again.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'valid' => $this->checkInService->validate($ticket)['valid'],
            'status' => $this->checkInService->validate($ticket)['status'],
            'message' => $this->checkInService->validate($ticket)['message'],
            'data' => new TicketResource($ticket),
            'check_in' => $ticket->ticketCheckins()->latest('checked_in_at')->first()
                ? new CheckInResource($ticket->ticketCheckins()->latest('checked_in_at')->with('staff')->first())
                : null,
        ]);
    }

    /**
     * Single-step check-in: staff scans QR → token is validated and the
     * ticket is checked in atomically in one request.
     *
     * Status flow: DONE → ACTIVE → USED (auto-activates DONE tickets).
     *
     * If the ticket is already used, returns 409 with the existing check-in
     * record so the frontend can display the "already checked in" warning.
     *
     * Uses DB transaction + row lock (SELECT FOR UPDATE) to prevent
     * duplicate check-ins from concurrent scans of the same QR code.
     */
    public function checkIn(CheckInRequest $request)
    {
        $ticket = $this->checkInService->resolve($request->validated('ticket_code'));

        if ($ticket === null) {
            return response()->json([
                'success' => false,
                'status' => 'not_found',
                'message' => 'Invalid ticket. Please check the QR code and try again.',
            ], 422);
        }

        $actor = $request->user();

        // Authorization: organizers and staff may only check in tickets for
        // events that belong to their own organizer.
        if (in_array($actor->role, [Role::ORGANIZER->value, Role::EVENT_STAFF->value], true)) {
            $organizerId = $actor->activeOrganizer()?->id;
            if ($organizerId === null || $ticket->event?->organizer_id !== $organizerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to check in this ticket.',
                ], 403);
            }
        }

        try {
            $ticketCheckin = $this->checkInService->checkIn($ticket, $actor, [
                'device_name' => $request->validated('device_name'),
                'ip_address' => $request->ip(),
                'source' => $request->user()->role,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Check-in successful. Ticket is now used.',
                'data' => new TicketResource($ticket->fresh(['user', 'event', 'ticketType', 'booking'])),
                'check_in' => new CheckInResource($ticketCheckin),
            ]);
        } catch (CheckInException $e) {
            $payload = [
                'success' => false,
                'status' => $e->status(),
                'message' => $e->getMessage(),
            ];

            // Surface duplicate scans the same way the frontend expects for
            // the "already checked in" banner.
            if ($e->status() === 'used') {
                $existing = $ticket->ticketCheckins()
                    ->latest('checked_in_at')
                    ->with('staff')
                    ->first();

                $payload['already_checked_in'] = true;
                $payload['data'] = new TicketResource($ticket->fresh(['user', 'event', 'ticketType', 'booking']));
                $payload['check_in'] = $existing ? new CheckInResource($existing) : null;
            }

            return response()->json($payload, 409);
        }
    }

    /**
     * Check-in history visible to the acting staff member / organizer.
     * Scoped to their own organizer.
     */
    public function history(Request $request)
    {
        $query = TicketCheckin::query()
            ->with(['ticket.user', 'event', 'staff']);

        if ($request->user()->role !== Role::ADMIN->value) {
            $query->where('organizer_id', $request->user()->activeOrganizer()?->id);
        }

        return response()->json([
            'success' => true,
            'data' => CheckInResource::collection(
                $query->latest('checked_in_at')->paginate($request->get('per_page', 15))
            ),
        ]);
    }
}
