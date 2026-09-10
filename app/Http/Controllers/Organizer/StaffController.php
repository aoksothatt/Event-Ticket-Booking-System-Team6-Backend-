<?php

namespace App\Http\Controllers\Organizer;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffController extends Controller
{
    /**
     * List staff assigned to the authenticated organizer.
     */
    public function index(Request $request)
    {
        $organizerId = $this->resolveOrganizerId($request->user());

        if ($organizerId === null) {
            return response()->json([
                'success' => false,
                'message' => 'No organizer profile is linked to this account.',
            ], 422);
        }

        $staff = EventStaff::query()
            ->with('user')
            ->where('organizer_id', $organizerId)
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $staff->through(fn (EventStaff $link) => [
                'id' => $link->id,
                'is_active' => $link->is_active,
                'created_at' => $link->created_at,
                'user' => new UserResource($link->user),
            ]),
        ]);
    }

    /**
     * Assign an existing user (with role event_staff) to this organizer.
     */
    public function store(Request $request)
    {
        $organizerId = $this->resolveOrganizerId($request->user());

        if ($organizerId === null) {
            return response()->json([
                'success' => false,
                'message' => 'No organizer profile is linked to this account.',
            ], 422);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = User::findOrFail($validated['user_id']);

        DB::transaction(function () use ($user, $organizerId, $validated) {
            $user->update(['role' => Role::EVENT_STAFF->value]);

            EventStaff::updateOrCreate(
                ['user_id' => $user->id, 'organizer_id' => $organizerId],
                ['is_active' => $validated['is_active'] ?? true],
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Staff member assigned successfully.',
            'data' => new UserResource($user->load('eventStaff')),
        ], 201);
    }

    /**
     * Update an existing staff assignment.
     */
    public function update(Request $request, int $id)
    {
        $organizerId = $this->resolveOrganizerId($request->user());

        $assignment = EventStaff::where('id', $id)
            ->where('organizer_id', $organizerId)
            ->firstOrFail();

        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $assignment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Staff assignment updated.',
            'data' => $assignment->fresh('user'),
        ]);
    }

    /**
     * Remove a staff member from this organizer.
     */
    public function destroy(Request $request, int $id)
    {
        $organizerId = $this->resolveOrganizerId($request->user());

        $assignment = EventStaff::where('id', $id)
            ->where('organizer_id', $organizerId)
            ->firstOrFail();

        $assignment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Staff member removed successfully.',
        ]);
    }

    protected function resolveOrganizerId($user): ?int
    {
        if ($user->role === Role::ADMIN->value) {
            return request()->integer('organizer_id') ?: null;
        }

        return $user->activeOrganizer()?->id;
    }
}
