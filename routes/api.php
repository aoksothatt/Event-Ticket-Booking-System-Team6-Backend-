<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailOTPController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingItemController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\OrganizerController;
use App\Http\Controllers\TicketTypeController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\VenuesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/* Public routes */

Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

Route::post('/otp/send', [EmailOTPController::class, 'forgetPassword'])->name('otp.send');
Route::post('/otp/verify', [EmailOTPController::class, 'verifyOTP'])->name('otp.verify');
Route::post('/reset', [EmailOTPController::class, 'resetPassword'])->name('password.reset');

Route::get('/organizers', [OrganizerController::class, 'index'])->name('organizers.index');
Route::get('/organizers/{id}', [OrganizerController::class, 'show'])->name('organizers.show');




//testing by sothat

Route::apiResource('tickettype', TicketTypeController::class);
Route::apiResource('event', EventsController::class);
Route::apiResource('venus', VenuesController::class);
Route::apiResource('categories', CategoriesController::class);
Route::apiResource('organizer', OrganizerController::class);
Route::apiResource('user', UsersController::class);



/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES (any logged-in role)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api'])->group(function () {

    Route::get('/events', [EventsController::class, 'index'])->name('events.index');
    Route::get('/events/{id}', [EventsController::class, 'show'])->name('events.show');

    Route::get('/categories', [CategoriesController::class, 'index'])->name('categories.index');
});
/* Authenticated routes */
Route::middleware('auth:api')->group(function () {
    Route::get('/user', static fn(Request $request) => response()->json([
        'success' => true,
        'data' => $request->user(),
    ]))->name('auth.user');

    Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::match(['put', 'patch'], '/profile', [ProfileController::class, 'update'])
        ->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'changePassword'])
        ->name('profile.password.update');
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])
        ->name('profile.avatar.store');

    Route::post('/organizers', [OrganizerController::class, 'store'])->name('organizers.store');
});


/*
|--------------------------------------------------------------------------
| ADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::apiResource('users', UsersController::class);

    // Category management
    // Route::post('/categories', [CategoriesController::class, 'store']);
    // Route::put('/categories/{id}', [CategoriesController::class, 'update']);
    // Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

    Route::post('/venues', [VenuesController::class, 'store'])->name('venues.store');
    Route::match(['put', 'patch'], '/venues/{venue}', [VenuesController::class, 'update'])
        ->name('venues.update');
    Route::delete('/venues/{venue}', [VenuesController::class, 'destroy'])->name('venues.destroy');

    Route::delete('/organizers/{id}', [OrganizerController::class, 'destroy'])
        ->name('organizers.destroy');
});

/*
|--------------------------------------------------------------------------
| ORGANIZER + ADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:organizer,admin'])->group(function () {


    Route::match(['put', 'patch'], '/organizers/{id}', [OrganizerController::class, 'update'])
        ->name('organizers.update');
});

/* Customer, organizer, and administrator routes */
Route::middleware(['auth:api', 'role:customer,organizer,admin'])->group(function () {
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('/bookings/{id}', [BookingController::class, 'show'])->name('bookings.show');
    Route::match(['put', 'patch'], '/bookings/{id}', [BookingController::class, 'update'])
        ->name('bookings.update');
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy'])->name('bookings.destroy');

    // Booking routes
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/{id}', [BookingController::class, 'show']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Booking routes
    |--------------------------------------------------------------------------
    */

    Route::get('/bookingType', [BookingItemController::class, 'index']);
    Route::get('/bookingType', [BookingItemController::class, 'show']);
    /*
    |--------------------------------------------------------------------------
    | Review routes
    |--------------------------------------------------------------------------
    */

    // Route::post('/reviews', [ReviewsController::class, 'store']);

    /*
    |--------------------------------------------------------------------------
    | Payment routes
    |--------------------------------------------------------------------------
    */

    // Route::post('/payments', [PaymentsController::class, 'store']);

    /*
    |--------------------------------------------------------------------------
    | Profile routes
    |--------------------------------------------------------------------------
    */

    // Route::get('/profile', [ProfileController::class, 'show']);
    // Route::put('/profile', [ProfileController::class, 'update']);

    Route::get('/check-ins', [CheckInController::class, 'index'])->name('check-ins.index');
    Route::post('/check-ins', [CheckInController::class, 'store'])->name('check-ins.store');
    Route::get('/check-ins/{id}', [CheckInController::class, 'show'])->name('check-ins.show');
    Route::match(['put', 'patch'], '/check-ins/{id}', [CheckInController::class, 'update'])
        ->name('check-ins.update');
});
