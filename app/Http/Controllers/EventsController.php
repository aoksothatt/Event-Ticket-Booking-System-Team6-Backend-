<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventsController extends Controller
{
    /**
     * Relations always eager-loaded for the Vue homepage and event details.
     */
    private const HOMEPAGE_RELATIONS = [
        'organizer',
        'category',
        'venue',
        'images',
        'ticketTypes',
    ];

    /**
     * All events with search, filters, sorting, and pagination.
     *
     * Query params (all optional):
     *   search      – title/description text search (case-insensitive)
     *   category_id – filter by category
     *   status      – filter by status (draft/published/cancelled)
     *   is_trending – filter trending events (1/0)
     *   sort_by     – start_date | start_time | title | created_at (default: created_at)
     *   order       – asc | desc (default: desc)
     *   per_page    – items per page (default: 10)
     */
    public function index(Request $request)
    {
        $request->validate([
            'category_id' => 'sometimes|integer|exists:categories,id',
            'status' => 'sometimes|in:draft,published,cancelled',
            'is_trending' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'sort_by' => 'sometimes|in:start_date,start_time,title,created_at',
            'order' => 'sometimes|in:asc,desc',
        ]);

        $sortBy = $request->get('sort_by', 'created_at');
        $order = $request->get('order', 'desc') === 'asc' ? 'asc' : 'desc';

        $events = Event::with(self::HOMEPAGE_RELATIONS)
            ->when($request->filled('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%");
                });
            })
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where('category_id', (int) $request->query('category_id'));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', (string) $request->query('status'));
            })
            ->when($request->filled('is_trending'), function ($query) use ($request) {
                $query->where('is_trending', $request->boolean('is_trending'));
            })
            ->orderBy($sortBy, $order)
            ->paginate((int) $request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'message' => 'Events retrieved successfully',
            'data' => $events,
        ]);
    }

    /**
     * Upcoming events for the homepage banner: published events that the
     * admin manually flagged with `is_upcoming = true`. No automatic date
     * logic — the admin decides which events appear in the banner.
     */
    public function upcoming(Request $request)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $events = Event::with(self::HOMEPAGE_RELATIONS)
            ->upcomingFlagged()
            ->orderBy('start_date')
            ->orderBy('start_time')
            ->paginate((int) $request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'message' => 'Upcoming events retrieved successfully',
            'data' => $events,
        ]);
    }

    /**
     * Trending events for the homepage. Driven by the admin's manual
     * `is_trending` selection — only published, still-active events are
     * returned so drafts and past events never leak in.
     */
    public function trending()
    {
        $events = Event::with(self::HOMEPAGE_RELATIONS)
            ->trending()
            ->where('end_date', '>=', today()->toDateString())
            ->orderBy('start_date')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Trending events retrieved successfully',
            'data' => $events,
        ]);
    }

    /**
     * Published events for a single category (e.g. Football) on the homepage.
     */
    public function byCategory(Request $request, int $categoryId)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $events = Event::with(self::HOMEPAGE_RELATIONS)
            ->published()
            ->where('category_id', $categoryId)
            ->orderBy('start_date')
            ->orderBy('start_time')
            ->paginate((int) $request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'message' => 'Events for category retrieved successfully',
            'data' => $events,
        ]);
    }

    // Show event details
    public function show($id)
    {
        $event = Event::with(self::HOMEPAGE_RELATIONS)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Event retrieved successfully',
            'data' => $event,
        ]);
    }

    /**
     * Show event details by URL slug (e.g. /api/events/slug/cambodia-vs-thailand).
     */
    public function showBySlug(string $slug)
    {
        $event = Event::with(self::HOMEPAGE_RELATIONS)
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Event retrieved successfully',
            'data' => $event,
        ]);
    }

    /**
     * Manually set an event's trending status (admin-only).
     */
    public function setTrending(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'is_trending' => 'required|boolean',
        ]);

        $event->update([
            'is_trending' => $validated['is_trending'],
        ]);

        $message = $event->is_trending ? __('messages.event_added_trending') : __('messages.event_removed_trending');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $event->fresh(self::HOMEPAGE_RELATIONS),
        ]);
    }

    /**
     * Manually set an event's upcoming status (admin-only).
     */
    public function setUpcoming(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'is_upcoming' => 'required|boolean',
        ]);

        $event->update([
            'is_upcoming' => $validated['is_upcoming'],
        ]);

        $message = $event->is_upcoming ? __('messages.event_added_upcoming') : __('messages.event_removed_upcoming');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $event->fresh(self::HOMEPAGE_RELATIONS),
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
                $validated['slug'] = 'event-'.now()->timestamp;
            }
        }

        // Ensure description is never null (DB column is NOT NULL)
        $validated['description'] = $validated['description'] ?? '';

        // Store the uploaded banner image instead of its temp path
        if ($request->hasFile('banner')) {
            $validated['banner'] = $request->file('banner')->store('events', 'public');
        } elseif ($request->exists('banner') && ! $request->hasFile('banner')) {
            unset($validated['banner']);
        }

        // Use the organizer selected in the form (already validated as existing)
        $event = Event::create($validated);

        return response()->json([
            'success' => true,
            'message' => __('messages.event_created'),
            'data' => $event->load(['venue', 'category']),
        ], 201);
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
            'slug' => 'nullable|string|max:255|unique:events,slug,'.$id,
            'description' => 'nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'status' => 'sometimes|in:draft,published,cancelled',
            'is_trending' => 'sometimes|boolean',
        ]);

        // Auto-generate slug from title if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['title'] ?? $event->title);
            if (empty($validated['slug'])) {
                $validated['slug'] = 'event-'.now()->timestamp;
            }
        }

        // Store the uploaded banner image instead of its temp path
        if ($request->hasFile('banner')) {
            $validated['banner'] = $request->file('banner')->store('events', 'public');
        } elseif ($request->exists('banner') && ! $request->hasFile('banner')) {
            unset($validated['banner']);
        }

        $event->update($validated);

        return response()->json([
            'success' => true,
            'message' => __('messages.event_updated'),
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
            'message' => __('messages.event_deleted'),
        ]);
    }
}
