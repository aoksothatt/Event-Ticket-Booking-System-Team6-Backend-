<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Payments;
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
        $totalBookings = Booking::count();
        $totalRevenue = Payments::where('payment_status', 'paid')->sum('amount');
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
}
