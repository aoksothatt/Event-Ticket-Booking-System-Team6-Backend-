<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\Request;

class ReviewsController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Review::with('user', 'event')
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|exists:events,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
        ]);

        $userId = $request->user()->id;

        // Check if the user has already reviewed this event
        $existing = Review::where('event_id', $validated['event_id'])
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => __('messages.already_reviewed_event'),
                'data' => $existing->load('user', 'event'),
            ], 409);
        }

        $review = Review::create([
            'event_id' => $validated['event_id'],
            'user_id' => $userId,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => __('messages.review_created'),
            'data' => $review->load('user', 'event'),
        ], 201);
    }

    /**
     * Reviews belonging to the authenticated customer (for their dashboard).
     */
    public function my(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => Review::with('event')
                ->where('user_id', $request->user()->id)
                ->latest()
                ->get(),
        ]);
    }

    public function show($id)
    {
        $review = Review::with('user', 'event')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $review,
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();

        // Moderators (manage_reviews — admins are a super role) may update any
        // review to publish/reject it; customers stay scoped to their own.
        if ($user->hasPermission('manage_reviews')) {
            $review = Review::findOrFail($id);
        } else {
            $review = Review::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();
        }

        $validated = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'comment' => 'nullable|string',
            'status' => 'sometimes|string|max:20',
        ]);

        $review->update($validated);

        return response()->json([
            'success' => true,
            'message' => __('messages.review_updated'),
            'data' => $review,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        // Moderators (manage_reviews — admins are a super role) may delete any
        // review; customers stay scoped to their own.
        if ($user->hasPermission('manage_reviews')) {
            $review = Review::findOrFail($id);
        } else {
            $review = Review::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();
        }

        $review->delete();

        return response()->json([
            'success' => true,
            'message' => __('messages.review_deleted'),
        ]);
    }
}
