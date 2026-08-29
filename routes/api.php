<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailOTPController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\OrganizerController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\VenuesController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

// Authentication
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Password reset / OTP
Route::post('/otp/send', [EmailOTPController::class, 'forgetPassword']);
Route::post('/otp/verify', [EmailOTPController::class, 'verify']);
Route::post('/reset', [EmailOTPController::class, 'resetPassword']);

// Public organizers
Route::get('/organizers', [OrganizerController::class, 'index'])
    ->name('organizers.index');

Route::get('/organizers/{id}', [OrganizerController::class, 'show'])
    ->name('organizer.show');

// Public venues
Route::get('/venues', [VenuesController::class, 'index']);
Route::get('/venues/{venue}', [VenuesController::class, 'show']);

// Public event browsing
Route::get('/events', [EventsController::class, 'index'])->name('events.index');
Route::get('/events/{event}', [EventsController::class, 'show'])->name('events.show');

// Public categories
Route::get('/categories', [CategoriesController::class, 'index']);


/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api'])->group(function () {

    // Current authenticated user
    Route::get('/user', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => $request->user(),
        ]);
    });

    // Current user profile
    Route::get('/me', [AuthController::class, 'me']);

    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // Customer applies to become organizer
    Route::post('/organizers', [OrganizerController::class, 'store'])
        ->name('organizers.store');
});

<<<<<<< HEAD

/*
|--------------------------------------------------------------------------
| ADMIN ROUTES
|--------------------------------------------------------------------------
*/

=======
//admin route 
>>>>>>> 9c8b492c0ed8b3e4c5bd73d1e3faefeaa0d44499
Route::middleware(['auth:api', 'role:admin'])->group(function () {

    // User management
    Route::apiResource('users', UsersController::class);

    // Category management
    Route::post('/categories', [CategoriesController::class, 'store']);

    Route::put('/categories/{id}', [CategoriesController::class, 'update']);

    Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

    // Venue management
    Route::post('/venues', [VenuesController::class, 'store']);

    Route::put('/venues/{id}', [VenuesController::class, 'update']);

    Route::delete('/venues/{id}', [VenuesController::class, 'destroy']);

    // Organizer management
    Route::delete('/organizer/{id}', [OrganizerController::class, 'destroy'])
        ->name('organizers.destroy');
});

<<<<<<< HEAD

/*
|--------------------------------------------------------------------------
| ORGANIZER + ADMIN ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'role:organizer,admin'])->group(function () {

    // Event management
    Route::post('/events', [EventsController::class, 'store'])->name('events.store');

    Route::match(['put', 'patch'], '/events/{id}', [EventsController::class, 'update'])
        ->name('events.update');

    Route::delete('/events/{id}', [EventsController::class, 'destroy'])
        ->name('events.destroy');

    // Organizer profile update
    Route::put('/organizers/{id}', [OrganizerController::class, 'update'])
        ->name('organizer.update');
});


/*
|--------------------------------------------------------------------------
| CUSTOMER + ORGANIZER + ADMIN ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'role:customer,organizer,admin'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Booking routes
    |--------------------------------------------------------------------------
    */

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/{id}', [BookingController::class, 'show']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::delete('/bookings/{id}' ,[BookingController::class, 'destroy']);

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
=======
// organizer route
Route::middleware(['auth:api', 'role:organizer,admin'])->group(function () {

    // organizer permisstion here
});

// customer route
Route::middleware(['auth:api', 'role:customer,organizer,admin'])->group(function () {

    // BookingController, ReviewsController, PaymentsController, and ProfileController here
>>>>>>> 9c8b492c0ed8b3e4c5bd73d1e3faefeaa0d44499

});
