<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class EventsController extends Controller
{
    // get all events with search, filter, and pagination
    public function index(Request $request)
    {
        $events = Event::with(['venue', 'category', 'organizer', 'images'])
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
            'data' => $events,
        ]);
    }

    // Create new event
    public function store(Request $request)
    {

        $validated = $request->validate([
            'organizer_id' => 'required|exists:organizers,id',
            'category_id' => 'required|exists:categories,id',
            'venue_id' => 'required|exists:venues,id',

            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:events,slug',

            'description' => 'nullable|string',

            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',

            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',

            'banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',

            'status' => 'nullable|in:draft,published,cancelled',
        ]);

        // Auto-generate slug from title if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['title']);
            if (empty($validated['slug'])) {
                $validated['slug'] = 'event-' . now()->timestamp;
            }
        }

        // Ensure description is never null (DB column is NOT NULL)
        $validated['description'] = $validated['description'] ?? '';

        // Store the uploaded banner image instead of its temp path
        if ($request->hasFile('banner')) {
            $validated['banner'] = $request->file('banner')->store('events', 'public');
        } elseif ($request->exists('banner') && !$request->hasFile('banner')) {
            unset($validated['banner']);
        }

        // Use the organizer selected in the form (already validated as existing)
        $event = Event::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Event created successfully',
            'data' => $event->load(['venue', 'category']),
        ], 201);
    }

    // Show event details
    public function show($id)
    {
        $event = Event::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $event->load(['venue', 'category', 'ticketTypes', 'images', 'organizer']),
        ]);
    }

    // Update event details
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'organizer_id' => 'sometimes|exists:organizers,id',
            'venue_id' => 'sometimes|exists:venues,id',
            'category_id' => 'sometimes|exists:categories,id',
            'title' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:events,slug,' . $id,
            'description' => 'nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'status' => 'sometimes|in:draft,published,cancelled',
        ]);

        // Auto-generate slug from title if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['title'] ?? $event->title);
            if (empty($validated['slug'])) {
                $validated['slug'] = 'event-' . now()->timestamp;
            }
        }

        // Store the uploaded banner image instead of its temp path
        if ($request->hasFile('banner')) {
            $validated['banner'] = $request->file('banner')->store('events', 'public');
        } elseif ($request->exists('banner') && !$request->hasFile('banner')) {
            unset($validated['banner']);
        }

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
