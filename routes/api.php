<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\EmailOTPController;
use App\Http\Controllers\OrganizerController;
use App\Models\organizer;

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


// controller Organizer

Route::get('/organizers',[OrganizerController::class,'index'])->name('organizers.index');
Route::post('/organizers',[OrganizerController::class,'store'])->name('organizers.store');
Route::get('/organizers/{id}',[OrganizerController::class,'show'])->name('organizer.show');
Route::put('/organizers/{id}',[OrganizerController::class,'update'])->name('organizer.update');
Route::delete('/organizer/{id}',[OrganizerController::class,'destroy'])->name('organizers.destroy');
