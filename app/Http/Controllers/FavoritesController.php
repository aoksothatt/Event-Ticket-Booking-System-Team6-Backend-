<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventFavorite;
use Illuminate\Http\Request;

/**
 * Favorites are scoped to the authenticated JWT user only.
 * A user can only ever view, add, or remove their own favorites.
 */
class FavoritesController extends Controller
{
    /**
     * List the current user's favorited events.
     */
    public function index(Request $request)
    {
        $favorites = $request->user()
            ->favorites()
            ->with(['venue', 'category', 'organizer', 'images', 'ticketTypes'])
            ->latest('event_favorites.created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $favorites,
        ]);
    }

    /**
     * Add an event to the current user's favorites.
     */
    public function store(Request $request, $event)
    {
        $event = Event::findOrFail($event);

        EventFavorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'event_id' => $event->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Event added to favorites.',
            'data' => ['event_id' => $event->id],
        ], 201);
    }

    /**
     * Remove an event from the current user's favorites.
     */
    public function destroy(Request $request, $event)
    {
        $event = Event::findOrFail($event);

        $request->user()->favorites()->detach($event->id);

        return response()->json([
            'success' => true,
            'message' => 'Event removed from favorites.',
            'data' => ['event_id' => $event->id],
        ]);
    }
}