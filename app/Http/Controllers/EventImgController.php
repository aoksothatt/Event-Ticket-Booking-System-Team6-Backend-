<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventImg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EventImgController extends Controller
{
    // Get all 
    public function index()
    {
        $images = EventImg::with('event')
            ->orderBy('sort_order')
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $images,
        ]);
    }

    // Get all image one event
    public function eventImages(Event $event)
    {
        $images = $event->eventImages()
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $images,
        ]);
    }

    // Upload
    public function store(Request $request)
    {
        $validated = $request->validate([
            'event_id'   => 'required|exists:events,id',
            'image'      => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        // Upload image to storage/app/public/events
        $path = $request->file('image')->store('events', 'public');

        // Save image in database
        $eventImage = EventImg::create([
            'event_id'   => $validated['event_id'],
            'image'      => $path,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Event image uploaded successfully',
            'data' => $eventImage->load('event'),
        ], 201);
    }

    // Show one event image
    public function show(EventImg $eventImg)
    {
        return response()->json([
            'success' => true,
            'data' => $eventImg->load('event'),
        ]);
    }

    // Update event image
    public function update(Request $request, EventImg $eventImg)
    {
        $validated = $request->validate([
            'image'      => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        // If new image is uploaded
        if ($request->hasFile('image')) {

            if ($eventImg->image) {
                Storage::disk('public')->delete($eventImg->image);
            }

            $validated['image'] =
                $request->file('image')->store('events', 'public');
        }

        $eventImg->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Event image updated successfully',
            'data' => $eventImg->fresh('event'),
        ]);
    }

    // Delete event image
    public function destroy(EventImg $eventImg)
    {
        if ($eventImg->image) {
            Storage::disk('public')->delete($eventImg->image);
        }

        // Delete database
        $eventImg->delete();

        return response()->json([
            'success' => true,
            'message' => 'Event image deleted successfully',
        ]);
    }
}