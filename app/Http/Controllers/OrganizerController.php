<?php

namespace App\Http\Controllers;

use App\Models\Organizer;
use Illuminate\Http\Request;

class OrganizerController extends Controller
{
    // GET /api/organizers
    public function index()
    {
        $organizers = Organizer::with('user')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $organizers
        ], 200);
    }

    // POST /api/organizers
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'company_name' => 'nullable|string|max:150',
            'company_logo' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string',
            'is_verified' => 'sometimes|boolean',
        ]);

        $organizer = Organizer::create($validated);

        return response()->json([
            'success' => true,
            'message' => __('messages.organizer_created'),
            'data' => $organizer
        ], 201);
    }

    // GET /api/organizers/{id}
    public function show($id)
    {
        $organizer = Organizer::with([
            'user',
            'events'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $organizer
        ], 200);
    }

    // PUT /api/organizers/{id}
    public function update(Request $request, $id)
    {
        $organizer = Organizer::findOrFail($id);

        $validated = $request->validate([
            'company_name' => 'nullable|string|max:150',
            'company_logo' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string',
            'is_verified' => 'sometimes|boolean',
        ]);

        $organizer->update($validated);

        return response()->json([
            'success' => true,
            'message' => __('messages.organizer_updated'),
            'data' => $organizer
        ], 200);
    }

    // DELETE /api/organizers/{id}
    public function destroy($id)
    {
        $organizer = Organizer::findOrFail($id);

        $organizer->delete();

        return response()->json([
            'success' => true,
            'message' => __('messages.organizer_deleted')
        ], 200);
    }
}
