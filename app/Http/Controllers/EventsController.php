<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;

class EventsController extends Controller
{
    // get all events with search, filter, and pagination
    public function index(Request $request)
    {
        $events = Event::with(['venue', 'category', 'eventImages'])
            ->when($request->search, function ($query, $search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            })
            ->when($request->category_id, function ($query, $categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->latest()
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data'    => $events,
        ]);
    }

    // Create new event
    public function store(Request $request)
    {
        $validated = $request->validate([
            'venue_id'    => 'required|exists:venues,id',
            'category_id' => 'required|exists:categories,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date'  => 'required|date|after_or_equal:today',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'status'      => 'nullable|in:draft,published,cancelled',
        ]);

        $validated['organizer_id'] = $request->user()?->id ?? $request->input('organizer_id');
        $event = Event::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Event created successfully',
            'data'    => $event->load(['venue', 'category']),
        ], 201);
    }

    // Show event details
    public function show($id)
    {
        $event = Event::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $event->load([
                'venue',
                'category',
                'ticketTypes',
                'eventImages',
                'organizer'
            ]),
        ]);
    }

    // Update event details
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'venue_id' => 'sometimes|exists:venues,id',
            'category_id' => 'sometimes|exists:categories,id',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'status' => 'sometimes|in:draft,published,cancelled',
        ]);

        $event->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Event updated successfully',
            'data' => $event->fresh(['venue', 'category']),
        ]);
    }

    // Delete event
    public function destroy($id)
    {
        $event = Event::findOrFail($id);

        $event->delete();

        return response()->json([
            'success' => true,
            'message' => 'Event deleted successfully',
        ]);
    }
}
