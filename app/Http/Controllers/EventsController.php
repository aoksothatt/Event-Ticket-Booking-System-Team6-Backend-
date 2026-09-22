<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Event;
use App\Http\Resources\EventResource;
use App\Models\Setting;
use App\Models\User;
use App\Services\EventStatsService;
use Carbon\Carbon;
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

    public function __construct(
        private readonly EventStatsService $stats,
    ) {}

    /**
     * All events with search, filters, sorting, and pagination.
     *
     * Query params (all optional):
     *   search      – title/description text search (case-insensitive)
     *   category_id – filter by category
     *   status      – filter by status (draft/published/cancelled)
     *   filter      – all | trending | upcoming (server-side managed lists)
     *   is_trending – filter trending events (1/0)
     *   sort_by     – start_date | start_time | title | created_at (default: created_at)
     *   order       – asc | desc (default: desc)
     *   per_page    – items per page (default: 10)
     */
    public function index(Request $request)
    {
        $request->validate([
            'category_id' => 'sometimes|integer|exists:categories,id',
            'status' => 'sometimes|in:draft,published,cancelled,rejected',
            'filter' => 'sometimes|in:all,trending,upcoming,pending',
            'is_trending' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'sort_by' => 'sometimes|in:start_date,start_time,title,created_at',
            'order' => 'sometimes|in:asc,desc',
        ]);

        $sortBy = $request->get('sort_by', 'created_at');
        $order = $request->get('order', 'desc') === 'asc' ? 'asc' : 'desc';

        // Accept either `?search=` (navbar dropdown) or `?q=` (legacy).
        $search = trim((string) $request->query('search', $request->query('q', '')));

        $events = Event::with(self::HOMEPAGE_RELATIONS)
            ->when($search !== '', function ($query) use ($search) {
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
            ->when($request->filled('filter'), function ($query) use ($request) {
                match ($request->query('filter')) {
                    // Same rule as the public /events/trending endpoint.
                    'trending' => $query->trending()->where('end_date', '>=', today()->toDateString()),
                    // Same rule as the public /events/upcoming endpoint.
                    'upcoming' => $query->upcoming(),
                    // Awaiting-approval queue: events created as drafts by
                    // organizers (forced when admin approval is required).
                    'pending' => $query->where('status', 'draft'),
                    default => null, // "all" keeps every status
                };
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
     * Trending events for the customer homepage.
     * Driven by the admin's manual `is_trending` selection — only published,
     * still-active events are returned so drafts and past events never leak in.
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
     * Upcoming events for the customer homepage.
     * Published events whose start date has not passed yet.
     */
    public function upcoming()
    {
        $events = Event::with(self::HOMEPAGE_RELATIONS)
            ->upcoming()
            ->where('end_date', '>=', today()->toDateString())
            ->orderBy('start_date')
            ->orderBy('start_time')
            ->paginate();

        return response()->json([
            'success' => true,
            'message' => 'Upcoming events retrieved successfully',
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
    public function show(Request $request, $id)
    {
        $event = Event::with(self::HOMEPAGE_RELATIONS)
            ->findOrFail($id);

        // Real booking statistics (Tickets Sold / Revenue) for staff views only:
        // admins, organizers and event staff see them; customers and guests never
        // receive revenue data from the public event endpoint.
        $user = $request->user();
        if ($user && in_array($user->role, ['admin', 'organizer', 'event_staff'], true)) {
            $event->setAttribute('stats', $this->stats->forEvent((int) $event->id));
        }

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
     * "You Might Also Like" — ranks other bookable events by relevance to the
     * event currently being viewed.
     *
     * Relevance scoring (highest first):
     *   same category       +5
     *   same venue          +3
     *   same city           +2
     *   date within 30 days +2
     *   admin-trending      +2
     *   has bookings        +2
     *   user affinity       +2  (authenticated: categories they favorited/booked)
     *
     * The request is public so guests can browse recommendations without an
     * account; when a valid JWT is present the user's past engagement quietly
     * boosts matching categories. The linked event itself and any
     * cancelled/ended/unpublished events are always excluded.
     *
     * If there are too few relevant candidates the results are topped up with
     * popular / trending / upcoming published events.
     */
    public function recommendations(Request $request, $id)
    {
        $event = Event::with(['category', 'venue'])->findOrFail($id);

        $limit = min(max((int) $request->get('limit', 8), 1), 12);

        $now = today()->toDateString();
        $nearFrom = Carbon::parse($event->start_date)->subDays(30)->toDateString();
        $nearTo = Carbon::parse($event->start_date)->addDays(30)->toDateString();
        $city = $event->venue?->city;

        // Optional personalization for signed-in users: boost the categories
        // they have already favorited or booked before.
        $affinityCategoryIds = collect();
        if ($user = $this->optionalApiUser($request)) {
            $favorited = $user->favorites()->pluck('events.category_id');
            $bookedCategoryIds = Event::whereIn(
                'id',
                Booking::where('user_id', $user->id)->pluck('event_id')
            )->pluck('category_id');

            $affinityCategoryIds = collect($favorited)
                ->merge($bookedCategoryIds)
                ->filter()
                ->unique()
                ->values();
        }

        // Candidate pool: published, not yet ended, never the current event.
        $bookingsCount = fn ($q) => $q->whereNotIn('status', ['cancelled', 'expired']);

        $candidates = Event::query()
            ->published()
            ->where('end_date', '>=', $now)
            ->where('id', '!=', $event->id)
            ->with(['venue', 'category', 'images', 'ticketTypes'])
            ->withCount(['bookings' => $bookingsCount]);

        // Static relevance in SQL so the database ranks candidates for us.
        $scoreSql = '(CASE WHEN events.category_id = ? THEN 5 ELSE 0 END)'
            . ' + (CASE WHEN events.venue_id = ? THEN 3 ELSE 0 END)'
            . ($city
                ? ' + (CASE WHEN events.venue_id IN (SELECT id FROM venues WHERE city = ?) THEN 2 ELSE 0 END)'
                : '')
            . ' + (CASE WHEN events.start_date BETWEEN ?::date AND ?::date THEN 2 ELSE 0 END)'
            . ' + (CASE WHEN events.is_trending THEN 2 ELSE 0 END)';

        $scoreBindings = [$event->category_id, $event->venue_id];
        if ($city) {
            $scoreBindings[] = $city;
        }
        $scoreBindings[] = $nearFrom;
        $scoreBindings[] = $nearTo;

        $candidates
            ->orderByRaw($scoreSql, $scoreBindings)
            ->orderBy('start_date')
            ->orderBy('start_time');

        $recommended = $candidates->limit($limit * 3)->get();

        // Fallback: top up any shortfall with popular / trending / upcoming.
        if ($recommended->count() < $limit) {
            $takenIds = $recommended->pluck('id')->push($event->id);

            $fallback = Event::query()
                ->published()
                ->where('end_date', '>=', $now)
                ->whereNotIn('id', $takenIds)
                ->with(['venue', 'category', 'images', 'ticketTypes'])
                ->withCount(['bookings' => $bookingsCount])
                ->orderBy('is_trending', 'desc')
                ->orderByDesc('bookings_count')
                ->orderBy('start_date')
                ->limit($limit - $recommended->count())
                ->get();

            $recommended = $recommended->concat($fallback);
        }

        // Final ranking: re-score in PHP so popularity and the (optional)
        // user affinity boost participate alongside the static signals.
        $ranked = $recommended
            ->map(function (Event $candidate) use ($event, $nearFrom, $nearTo, $affinityCategoryIds) {
                $score = intval($candidate->category_id === $event->category_id ? 5 : 0)
                    + intval($candidate->venue_id === $event->venue_id ? 3 : 0)
                    + intval($candidate->venue && $event->venue
                        && $candidate->venue->city && $candidate->venue->city === $event->venue->city ? 2 : 0)
                    + intval($candidate->start_date->between($nearFrom, $nearTo) ? 2 : 0)
                    + intval($candidate->is_trending ? 2 : 0)
                    + intval($candidate->bookings_count >= 1 ? 2 : 0)
                    + intval($affinityCategoryIds->contains($candidate->category_id) ? 2 : 0);

                $candidate->recommendation_score = $score;

                return $candidate;
            })
            ->sort(function ($a, $b) {
                return [
                    $b->recommendation_score,
                    $b->bookings_count,
                    $a->start_date->timestamp,
                ] <=> [
                    $a->recommendation_score,
                    $a->bookings_count,
                    $b->start_date->timestamp,
                ];
            })
            ->values()
            ->take($limit);

        // Strip the internal scoring helpers from the response payload.
        $ranked->each(function (Event $candidate) {
            unset($candidate->recommendation_score);
            unset($candidate->bookings_count);
        });

        return response()->json([
            'success' => true,
            'message' => 'Recommendations retrieved successfully',
            'data' => $ranked,
        ]);
    }

    /**
     * Resolve the current JWT user when a token was sent, without ever
     * failing the request for guests or for invalid/expired tokens.
     */
    private function optionalApiUser(Request $request): ?User
    {
        try {
            return $request->user('api');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Manually set an event's trending status (admin-only).
     *
     * Upcoming needs no equivalent toggle: it is derived automatically from
     * the event's published status and start date (see Event::scopeUpcoming).
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
     * Approve a pending event (admin-only).
     *
     * The approval workflow reuses the existing status column: organizer
     * submissions land as "draft" when administrator approval is required,
     * and approve() is the only path that publishes them. Any stored rejection
     * reason is cleared so a re-submission starts clean.
     */
    public function approve($id)
    {
        $event = Event::findOrFail($id);

        $event->update([
            'status' => 'published',
            'rejection_reason' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.event_approved'),
            'data' => $event->fresh(self::HOMEPAGE_RELATIONS),
        ]);
    }

    /**
     * Reject a pending event with an optional reason (admin-only).
     *
     * Rejected events can be revised and resubmitted by the organizer (which
     * sets them back to draft) before an admin approves them.
     */
    public function reject(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $event->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.event_rejected'),
            'data' => $event->fresh(self::HOMEPAGE_RELATIONS),
        ]);
    }

    // Create new event
    public function store(Request $request)
    {
        $user = $request->user();

        // Organizer submissions are governed by the platform settings. Admins
        // (the super role) always retain the ability to create events.
        if (! $user->hasRole('admin')
            && ! Setting::value('event.organizer_create_enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Organizers are not currently allowed to create events on this platform.',
            ], 403);
        }

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

            'banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',

            'status' => 'nullable|in:draft,published,cancelled',
        ]);

        // When administrator approval is required, organizer-created events are
        // always saved as drafts and only an admin may publish them later.
        if (! $user->hasRole('admin')
            && Setting::value('event.admin_approval_required', false)) {
            $validated['status'] = 'draft';
        }

        // Auto-generate slug from title if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['title']);
            if (empty($validated['slug'])) {
                $validated['slug'] = 'event-'.now()->timestamp;
            }
        }

        // Ensure description is never null (DB column is NOT NULL)
        $validated['description'] = $validated['description'] ?? '';

        // Default status to published when not specified
        $validated['status'] = $validated['status'] ?? 'published';

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
        $user = $request->user();

        // Publishing is an admin action when approval is required: without it
        // organizers could bypass the approval flow by creating a draft and
        // immediately publishing it themselves.
        if (! $user->hasRole('admin')
            && Setting::value('event.admin_approval_required', false)
            && $request->input('status') === 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Events must be approved by an administrator before publishing.',
            ], 403);
        }

        // Once an event is published, editing may be locked down so
        // organizers cannot silently change details customers already bought
        // against. Admins always retain the ability to edit.
        if (! $user->hasRole('admin')
            && $event->status === 'published'
            && ! Setting::value('event.allow_edit_after_publish', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Published events can no longer be edited on this platform.',
            ], 403);
        }

        // Cancellation of a published event is a separate platform-level switch.
        if (! $user->hasRole('admin')
            && $request->input('status') === 'cancelled'
            && ! Setting::value('event.allow_cancellation', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Event cancellation is currently disabled on this platform.',
            ], 403);
        }

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
            'banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'status' => 'sometimes|in:draft,published,cancelled,rejected',
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
