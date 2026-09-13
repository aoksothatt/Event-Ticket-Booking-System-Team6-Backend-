<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Category;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\EventFavorite;
use App\Models\Organizer;
use App\Models\Payments;
use App\Models\Review;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $totalUsers = User::count();
        $totalOrganizers = Organizer::count();
        $totalEvents = Event::count();
        $totalCategories = Category::count();
        $totalVenues = Venue::count();
        $totalRevenue = Payments::where(fn ($q) => $q->where('payment_status', 'paid')->orWhere('status', 'paid'))->sum('amount');
        $totalBookings = Booking::count();
        $pendingBookings = Booking::where('status', 'pending')->count();
        $activeEvents = Event::where('status', 'published')->count();
        $activeUsers = User::where('status', 'active')->count();
        $verifiedOrganizers = Organizer::where('is_verified', true)->count();
        $totalTicketsSold = \App\Models\TicketType::sum('sold_quantity');

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully',
            'data' => [
                'total_users' => $totalUsers,
                'total_organizers' => $totalOrganizers,
                'total_events' => $totalEvents,
                'total_categories' => $totalCategories,
                'total_venues' => $totalVenues,
                'total_bookings' => $totalBookings,
                'total_revenue' => number_format((float) $totalRevenue, 2, '.', ''),
                'pending_bookings' => $pendingBookings,
                'active_events' => $activeEvents,
                'active_users' => $activeUsers,
                'verified_organizers' => $verifiedOrganizers,
                'total_tickets_sold' => $totalTicketsSold,
            ],
        ]);
    }

    /**
     * Aggregated totals for a single authenticated customer's dashboard.
     * Everything is scoped to the authenticated user's own data.
     */
    public function my(Request $request)
    {
        $user = $request->user();

        $totalBookings = Booking::where('user_id', $user->id)->count();
        $totalSpent = Payments::where(fn ($q) => $q->where('payment_status', 'paid')->orWhere('status', 'paid'))
            ->whereHas('booking', fn ($q) => $q->where('user_id', $user->id))
            ->sum('amount');

        $totalTickets = Ticket::where('user_id', $user->id)->count();
        $activeTickets = Ticket::where('user_id', $user->id)->where('status', 'active')->count();
        $usedTickets = Ticket::where('user_id', $user->id)->where('status', 'used')->count();

        $totalCheckIns = CheckIn::whereHas('booking', fn ($q) => $q->where('user_id', $user->id))->count();
        $checkedInCount = CheckIn::where('status', 'checked_in')
            ->whereHas('booking', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        $totalReviews = Review::where('user_id', $user->id)->count();
        $avgRating = Review::where('user_id', $user->id)->avg('rating');

        $totalFavorites = EventFavorite::where('user_id', $user->id)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_bookings' => $totalBookings,
                'total_spent' => number_format((float) $totalSpent, 2, '.', ''),
                'total_tickets' => $totalTickets,
                'active_tickets' => $activeTickets,
                'used_tickets' => $usedTickets,
                'total_checkins' => $totalCheckIns,
                'checked_in' => $checkedInCount,
                'total_reviews' => $totalReviews,
                'avg_rating' => $avgRating ? round((float) $avgRating, 1) : null,
                'total_favorites' => $totalFavorites,
            ],
        ]);
    }
}
