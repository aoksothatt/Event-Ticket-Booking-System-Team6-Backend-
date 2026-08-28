<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailOTPController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\OrganizerController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\VenuesController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES (no auth)
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/otp/send', [EmailOTPController::class, 'forgetPassword']);
Route::post('/otp/verify', [EmailOTPController::class, 'verify']);
Route::post('/reset', [EmailOTPController::class, 'resetPassword']);

// Public browsing (anyone can view listings)
Route::get('/organizers', [OrganizerController::class, 'index'])->name('organizers.index');
Route::get('/organizers/{id}', [OrganizerController::class, 'show'])->name('organizer.show');
Route::get('/venues', [VenuesController::class, 'index']);
Route::get('/venues/{venue}', [VenuesController::class, 'show']);
Route::get('/events', [EventsController::class, 'index']);
Route::get('/events/{event}', [EventsController::class, 'show']);
Route::get('/categories', [CategoriesController::class, 'index']);

/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES (any logged-in role)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api'])->group(function () {

    // Current authenticated user
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Current user profile info from JWT
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Apply to become an organizer (customer -> organizer)
    Route::post('/organizers', [OrganizerController::class, 'store'])->name('organizers.store');
});

/*
|--------------------------------------------------------------------------
| ADMIN ROUTES (role:admin only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:admin'])->group(function () {

    // Full user management
    Route::apiResource('users', UsersController::class);

    // Category management
    Route::post('/categories', [CategoriesController::class, 'store']);
    Route::put('/categories/{id}', [CategoriesController::class, 'update']);
    Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

    // Venue management
    Route::post('/venues', [VenuesController::class, 'store']);
    Route::put('/venues/{venue}', [VenuesController::class, 'update']);
    Route::delete('/venues/{venue}', [VenuesController::class, 'destroy']);

    // Organizer management (delete / force etc.)
    Route::delete('/organizer/{id}', [OrganizerController::class, 'destroy'])->name('organizers.destroy');
});

/*
|--------------------------------------------------------------------------
| ORGANIZER ROUTES (role:organizer + admin override)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:organizer,admin'])->group(function () {

    // Manage own events
    Route::post('/events', [EventsController::class, 'store']);
    Route::put('/events/{event}', [EventsController::class, 'update']);
    Route::delete('/events/{event}', [EventsController::class, 'destroy']);

    // Update own organizer profile
    Route::put('/organizers/{id}', [OrganizerController::class, 'update'])->name('organizer.update');
});

/*
|--------------------------------------------------------------------------
| CUSTOMER ROUTES (role:customer + organizer/admin view override)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:customer,organizer,admin'])->group(function () {

    // Example: customer books, organizer/admin can view everything
    // Route::post('/bookings', [BookingController::class, 'store']);
    // Route::get('/bookings', [BookingController::class, 'index']);
    // Route::get('/bookings/{id}', [BookingController::class, 'show']);

    // Example: leave a review
    // Route::post('/reviews', [ReviewsController::class, 'store']);

    // Example: make a payment
    // Route::post('/payments', [PaymentsController::class, 'store']);

    // Example: own profile management
    // Route::get('/profile', [ProfileController::class, 'show']);
    // Route::put('/profile', [ProfileController::class, 'update']);



    // BookingController, ReviewsController, PaymentsController, and ProfileController here


});
