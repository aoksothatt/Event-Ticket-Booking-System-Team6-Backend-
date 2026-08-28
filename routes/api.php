<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\EmailOTPController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\VenuesController;
use App\Http\Controllers\EventsController; // 1. Import Controller

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

// Resources
Route::apiResource('users', UsersController::class);
Route::apiResource('venues', VenuesController::class);
Route::apiResource('events', EventsController::class); // 2. បន្ថែម Events Route