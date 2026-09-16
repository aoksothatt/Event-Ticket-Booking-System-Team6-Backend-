<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventImgResource;
use App\Models\Event;
use App\Models\EventImg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'data' => EventImgResource::collection($images),
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
            'data' => EventImgResource::collection($images),
        ]);
    }

    // Upload
    public function store(Request $request)
    {
        $validated = $request->validate([
            'event_id'   => 'required|exists:events,id',
            'image'      => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'nullable|integer|min:1',
        ]);

        // Upload image to storage/app/public/events
        $path = $request->file('image')->store('events', 'public');

        // Appended after the last image unless an explicit slot is given
        $sortOrder = $validated['sort_order']
            ?? EventImg::where('event_id', $validated['event_id'])->max('sort_order') + 1;

        // Save image in database
        $eventImage = EventImg::create([
            'event_id'   => $validated['event_id'],
            'image'      => $path,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.event_image_uploaded'),
            'data' => EventImgResource::make($eventImage->load('event')),
        ], 201);
    }

    // Show one event image
    public function show(EventImg $eventImg)
    {
        return response()->json([
            'success' => true,
            'data' => EventImgResource::make($eventImg->load('event')),
        ]);
    }

    // Update event image
    public function update(Request $request, EventImg $eventImg)
    {
        $validated = $request->validate([
            'image'      => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sort_order' => 'sometimes|integer|min:1',
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
            'message' => __('messages.event_image_updated'),
            'data' => EventImgResource::make($eventImg->fresh('event')),
        ]);
    }

    // Delete event image
    public function destroy(EventImg $eventImg)
    {
        $eventId = $eventImg->event_id;

        if ($eventImg->image) {
            Storage::disk('public')->delete($eventImg->image);
        }

        DB::transaction(function () use ($eventImg, $eventId) {
            // Delete database
            $eventImg->delete();
            // Keep sort_order contiguous so "1 = primary poster" always holds
            $this->normalizeSortOrder($eventId);
        });

        return response()->json([
            'success' => true,
            'message' => __('messages.event_image_deleted'),
        ]);
    }

    // Bulk update sort_order for an event's images
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'event_id'      => 'required|exists:events,id',
            'images'        => 'required|array|min:1',
            'images.*.id'   => 'required|exists:event_imgs,id',
            'images.*.sort_order' => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['images'] as $item) {
                EventImg::where('id', $item['id'])
                    ->where('event_id', $validated['event_id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        $images = EventImg::where('event_id', $validated['event_id'])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'message' => __('messages.event_images_reordered'),
            'data' => EventImgResource::collection($images),
        ]);
    }

    // Promote an image to the primary poster (sort_order = 1)
    public function setPrimary(EventImg $eventImg)
    {
        DB::transaction(function () use ($eventImg) {
            // Push every other image down by one slot, then take the top spot
            EventImg::where('event_id', $eventImg->event_id)
                ->where('id', '!=', $eventImg->id)
                ->increment('sort_order');

            $eventImg->update(['sort_order' => 1]);

            $this->normalizeSortOrder($eventImg->event_id);
        });

        return response()->json([
            'success' => true,
            'message' => __('messages.event_image_primary'),
            'data' => EventImgResource::make($eventImg->fresh()),
        ]);
    }

    // Renumber an event's images to 1..n (ascending by current sort_order)
    private function normalizeSortOrder(int $eventId): void
    {
        $images = EventImg::where('event_id', $eventId)
            ->orderBy('sort_order', 'asc')
            ->get();

        $images->each(function (EventImg $img, int $index) {
            $img->update(['sort_order' => $index + 1]);
        });
    }
}