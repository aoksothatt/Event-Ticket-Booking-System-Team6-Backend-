<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailOTPController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingItemController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\Customer\TicketController as CustomerTicketController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\Organizer\DashboardController as OrganizerDashboardController;
use App\Http\Controllers\Organizer\StaffController as OrganizerStaffController;
use App\Http\Controllers\OrganizerController;
use App\Http\Controllers\PaymentsController;
use App\Http\Controllers\ReviewsController;
use App\Http\Controllers\Staff\CheckInController as StaffCheckInController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketTypeController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\VenuesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes (no authentication required)
|--------------------------------------------------------------------------
*/

/* ----- Clean /api/auth/* namespace (new architecture) ----- */
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/otp/send', [EmailOTPController::class, 'forgetPassword'])->middleware('throttle:otp');
    Route::post('/otp/verify', [EmailOTPController::class, 'verifyOTP'])->middleware('throttle:otp');
    Route::post('/reset', [EmailOTPController::class, 'resetPassword'])->middleware('throttle:otp');
});

/* ----- Legacy auth endpoints (kept for frontend compatibility) ----- */
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/otp/send', [EmailOTPController::class, 'forgetPassword'])->middleware('throttle:otp');
Route::post('/otp/verify', [EmailOTPController::class, 'verifyOTP'])->middleware('throttle:otp');
Route::post('/reset', [EmailOTPController::class, 'resetPassword'])->middleware('throttle:otp');

Route::get('/organizers', [OrganizerController::class, 'index']);
Route::get('/organizers/{id}', [OrganizerController::class, 'show']);
Route::get('/venues', [VenuesController::class, 'index']);
Route::get('/venues/{id}', [VenuesController::class, 'show']);
Route::get('/events', [EventsController::class, 'index']);
Route::get('/events/trending', [EventsController::class, 'trending']);
Route::get('/events/{id}', [EventsController::class, 'show']);
Route::get('/categories', [CategoriesController::class, 'index']);
Route::get('/ticket-types', [TicketTypeController::class, 'index']);
Route::get('/ticket-types/{id}', [TicketTypeController::class, 'show']);
Route::get('/reviews', [ReviewsController::class, 'index']);
Route::get('/reviews/{id}', [ReviewsController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Authenticated routes (any role)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api'])->group(function () {
    Route::get('/user', static fn (Request $request) => response()->json([
        'success' => true,
        'data' => $request->user(),
    ]));
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::match(['put', 'patch'], '/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'changePassword']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);

    Route::post('/organizers', [OrganizerController::class, 'store']);

    Route::get('/user/favorites', [FavoritesController::class, 'index']);
    Route::post('/events/{event}/favorite', [FavoritesController::class, 'store']);
    Route::delete('/events/{event}/favorite', [FavoritesController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Administrator routes (role: admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::apiResource('users', UsersController::class);

    Route::post('/categories', [CategoriesController::class, 'store']);
    Route::match(['put', 'patch'], '/categories/{id}', [CategoriesController::class, 'update']);
    Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

    Route::post('/venues', [VenuesController::class, 'store']);
    Route::match(['put', 'patch'], '/venues/{id}', [VenuesController::class, 'update']);
    Route::delete('/venues/{id}', [VenuesController::class, 'destroy']);

    Route::delete('/organizers/{id}', [OrganizerController::class, 'destroy']);

    Route::patch('/events/{id}/trending', [EventsController::class, 'setTrending']);

    // Organizer management helpers for admins.
    Route::post('/organizers', [OrganizerController::class, 'store']);
    Route::patch('/organizers/{id}/verify', [OrganizerController::class, 'update']);

    // Staff management (admin acting on a specific organizer + users).
    Route::get('/staff', [OrganizerStaffController::class, 'index']);
    Route::post('/staff', [OrganizerStaffController::class, 'store']);
    Route::put('/staff/{id}', [OrganizerStaffController::class, 'update']);
    Route::delete('/staff/{id}', [OrganizerStaffController::class, 'destroy']);
});

/* ----- Legacy admin routes (kept for frontend compatibility) ----- */
Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/dashboard', [DashboardController::class, 'index']);
    Route::apiResource('users', UsersController::class);
    Route::post('/categories', [CategoriesController::class, 'store']);
    Route::match(['put', 'patch'], '/categories/{id}', [CategoriesController::class, 'update']);
    Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);
    Route::post('/venues', [VenuesController::class, 'store']);
    Route::match(['put', 'patch'], '/venues/{id}', [VenuesController::class, 'update']);
    Route::delete('/venues/{id}', [VenuesController::class, 'destroy']);
    Route::delete('/organizers/{id}', [OrganizerController::class, 'destroy']);
    Route::patch('/admin/events/{id}/trending', [EventsController::class, 'setTrending']);
});

/*
|--------------------------------------------------------------------------
| Organizer routes (role: organizer + admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:organizer,admin'])->prefix('organizer')->group(function () {
    Route::get('/dashboard', [OrganizerDashboardController::class, 'index']);
    Route::get('/events', [EventsController::class, 'index']);
    Route::post('/events', [EventsController::class, 'store']);
    Route::get('/events/{id}', [EventsController::class, 'show']);
    Route::match(['put', 'patch'], '/events/{id}', [EventsController::class, 'update']);
    Route::delete('/events/{id}', [EventsController::class, 'destroy']);
    Route::get('/events/{event}/attendance', [OrganizerDashboardController::class, 'attendance']);

    Route::get('/staff', [OrganizerStaffController::class, 'index']);
    Route::post('/staff', [OrganizerStaffController::class, 'store']);
    Route::put('/staff/{id}', [OrganizerStaffController::class, 'update']);
    Route::delete('/staff/{id}', [OrganizerStaffController::class, 'destroy']);

    Route::post('/ticket-types', [TicketTypeController::class, 'store']);
    Route::match(['put', 'patch'], '/ticket-types/{id}', [TicketTypeController::class, 'update']);
    Route::delete('/ticket-types/{id}', [TicketTypeController::class, 'destroy']);
    Route::patch('/ticket-types/{id}/status', [TicketTypeController::class, 'setStatus']);

    Route::get('/bookings', [BookingController::class, 'index']);
});

/* ----- Legacy organizer routes (kept for frontend compatibility) ----- */
Route::middleware(['auth:api', 'role:organizer,admin'])->group(function () {
    Route::post('/events', [EventsController::class, 'store']);
    Route::match(['put', 'patch'], '/events/{id}', [EventsController::class, 'update']);
    Route::delete('/events/{id}', [EventsController::class, 'destroy']);
    Route::match(['put', 'patch'], '/organizers/{id}', [OrganizerController::class, 'update']);

    Route::post('/ticket-types', [TicketTypeController::class, 'store']);
    Route::match(['put', 'patch'], '/ticket-types/{id}', [TicketTypeController::class, 'update']);
    Route::delete('/ticket-types/{id}', [TicketTypeController::class, 'destroy']);
    Route::patch('/ticket-types/{id}/status', [TicketTypeController::class, 'setStatus']);

    Route::get('/check-ins', [CheckInController::class, 'index']);
    Route::post('/check-ins', [CheckInController::class, 'store']);
    Route::get('/check-ins/{id}', [CheckInController::class, 'show']);
    Route::match(['put', 'patch'], '/check-ins/{id}', [CheckInController::class, 'update']);

    Route::get('/tickets', [TicketController::class, 'index']);
    Route::get('/tickets/{id}', [TicketController::class, 'show']);
    Route::post('/tickets/lookup', [TicketController::class, 'lookup']);
    Route::post('/tickets/check-in', [TicketController::class, 'checkIn']);
    Route::post('/tickets/verify', [TicketController::class, 'verify']);
    Route::post('/tickets/{id}/cancel', [TicketController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| Staff routes (role: event_staff, organizer, admin) — QR check-in
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:event_staff,organizer,admin'])->prefix('staff')->group(function () {
    Route::post('/check-in/lookup', [StaffCheckInController::class, 'lookup']);
    Route::post('/check-in', [StaffCheckInController::class, 'checkIn'])->middleware('throttle:checkin');
    Route::get('/check-in/history', [StaffCheckInController::class, 'history']);

    Route::get('/events/{event}/attendance', [OrganizerDashboardController::class, 'attendance']);
});

/* ----- Legacy staff-facing check-in aliases (kept for scanner compatibility) ----- */
Route::middleware(['auth:api', 'role:event_staff,organizer,admin'])->group(function () {
    Route::post('/tickets/lookup', [TicketController::class, 'lookup'])->middleware('throttle:checkin');
    Route::post('/tickets/check-in', [TicketController::class, 'checkIn'])->middleware('throttle:checkin');
    Route::post('/tickets/verify', [TicketController::class, 'verify'])->middleware('throttle:checkin');
});

/*
|--------------------------------------------------------------------------
| Customer routes (role: customer, organizer, admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:customer,organizer,admin'])->prefix('customer')->group(function () {
    Route::get('/tickets', [CustomerTicketController::class, 'myTickets']);
    Route::get('/tickets/history', [CustomerTicketController::class, 'history']);
    Route::post('/tickets/self-checkin', [CustomerTicketController::class, 'selfCheckIn'])->middleware('throttle:checkin');
    Route::get('/summary', [DashboardController::class, 'my']);
});

Route::middleware(['auth:api', 'role:customer,organizer,admin'])->group(function () {
    Route::get('/my-tickets', [TicketController::class, 'myTickets']);
    Route::get('/my/tickets/history', [TicketController::class, 'history']);
    Route::post('/my/tickets/self-checkin', [TicketController::class, 'selfCheckIn'])->middleware('throttle:checkin');

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings/{id}', [BookingController::class, 'show']);
    Route::match(['put', 'patch'], '/bookings/{id}', [BookingController::class, 'update']);
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy']);

    Route::get('/booking-items', [BookingItemController::class, 'index']);
    Route::get('/booking-items/{id}', [BookingItemController::class, 'show']);

    Route::get('/my/summary', [DashboardController::class, 'my']);
    Route::get('/my/payments', [PaymentsController::class, 'my']);
    Route::get('/my/check-ins', [CheckInController::class, 'my']);
    Route::get('/my/reviews', [ReviewsController::class, 'my']);

    Route::post('/reviews', [ReviewsController::class, 'store']);
    Route::match(['put', 'patch'], '/reviews/{id}', [ReviewsController::class, 'update']);
    Route::delete('/reviews/{id}', [ReviewsController::class, 'destroy']);

    Route::get('/payments', [PaymentsController::class, 'index']);
    Route::post('/payments', [PaymentsController::class, 'store']);
});
