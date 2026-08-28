<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\EmailOTPController;
<<<<<<< HEAD
use App\Http\Controllers\OrganizerController;
use App\Models\organizer;
=======
use App\Http\Controllers\UsersController;
use App\Http\Controllers\VenuesController;
>>>>>>> 47d5305bcd0408a6d6309f9228d64f38b5635438

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// auth route
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// email otp
Route::post('/otp/send', [EmailOTPController::class, 'forgetPassword']);
Route::post('/otp/verify', [EmailOTPController::class, 'verify']);
Route::post('/reset', [EmailOTPController::class, 'resetPassword']);
<<<<<<< HEAD


// controller Organizer

Route::get('/organizers',[OrganizerController::class,'index'])->name('organizers.index');
Route::post('/organizers',[OrganizerController::class,'store'])->name('organizers.store');
Route::get('/organizers/{id}',[OrganizerController::class,'show'])->name('organizer.show');
Route::put('/organizers/{id}',[OrganizerController::class,'update'])->name('organizer.update');
Route::delete('/organizer/{id}',[OrganizerController::class,'destroy'])->name('organizers.destroy');
=======
//
Route::apiResource('users', UsersController::class);
Route::apiResource('venues',VenuesController::class);

>>>>>>> 47d5305bcd0408a6d6309f9228d64f38b5635438
