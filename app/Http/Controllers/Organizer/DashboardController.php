<?php

namespace App\Http\Controllers\Organizer;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\CheckInResource;
use App\Services\OrganizerDashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly OrganizerDashboardService $dashboard,
    ) {}

    public function index(Request $request)
    {
        $organizerId = $this->resolveOrganizerId($request->user());

        if ($organizerId === null) {
            return response()->json([
                'success' => false,
                'message' => 'No organizer profile is linked to this account.',
            ], 422);
        }

        $stats = $this->dashboard->stats($organizerId);

        $stats['recent_checkins'] = CheckInResource::collection($stats['recent_checkins']);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    public function attendance(Request $request, int $event)
    {
        $organizerId = $this->resolveOrganizerId($request->user());

        if ($organizerId === null) {
            return response()->json([
                'success' => false,
                'message' => 'No organizer profile is linked to this account.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->dashboard->eventAttendance($organizerId, $event),
        ]);
    }

    protected function resolveOrganizerId($user): ?int
    {
        if ($user->role === Role::ADMIN->value) {
            // Admins viewing the organizer dashboard must specify one.
            return request()->integer('organizer_id') ?: null;
        }

        return $user->activeOrganizer()?->id;
    }
}
