<?php

namespace App\Http\Controllers;

use App\Models\Venue;
use Illuminate\Http\Request;

class VenuesController extends Controller
{
    // GET ALL VENUES + SEARCH + PAGINATION
    public function index(Request $request)
    {
        $query = Venue::query();

        if ($request->filled('search')) {
            // Escape % and _ for SQL LIKE search
            $search = addcslashes($request->search, '%_');

            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('city', 'ilike', "%{$search}%")
                    ->orWhere('address', 'ilike', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate(10),
        ]);
    }

    // CREATE VENUE
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:150',
            'address'     => 'required|string|max:255',
            'city'        => 'required|string|max:100',
            'province'    => 'nullable|string|max:100',
            'country'     => 'required|string|max:100',
            'capacity'    => 'required|integer|min:1',
            'description' => 'nullable|string',
            'status'      => 'nullable|string|in:active,inactive',
        ]);

        $validated['status'] = $validated['status'] ?? 'active';

        $venue = Venue::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Venue created successfully',
            'data' => $venue,
        ], 201);
    }

    // GET ONE VENUE BY ID
    public function show($id)
    {
        $venue = Venue::find($id);

        if (!$venue) {
            return response()->json([
                'success' => false,
                'message' => 'Venue not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $venue,
        ]);
    }

    // UPDATE VENUE BY ID
    public function update(Request $request, $id)
    {
        $venue = Venue::find($id);

        if (!$venue) {
            return response()->json([
                'success' => false,
                'message' => 'Venue not found',
            ], 404);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:150',
            'address'     => 'sometimes|string|max:255',
            'city'        => 'sometimes|string|max:100',
            'province'    => 'nullable|string|max:100',
            'country'    => 'sometimes|string|max:100',
            'capacity'    => 'sometimes|integer|min:1',
            'description' => 'nullable|string',
            'status'      => 'sometimes|string|in:active,inactive',
        ]);

        $venue->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Venue updated successfully',
            'data' => $venue,
        ]);
    }

    // DELETE VENUE BY ID
    public function destroy($id)
    {
        $venue = Venue::find($id);

        if (!$venue) {
            return response()->json([
                'success' => false,
                'message' => 'Venue not found',
            ], 404);
        }

        $venue->delete();

        return response()->json([
            'success' => true,
            'message' => 'Venue deleted successfully',
        ]);
    }
}
