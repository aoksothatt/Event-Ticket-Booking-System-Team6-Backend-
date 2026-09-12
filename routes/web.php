<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use App\Http\Controllers\Auth\GoogleAuthController;

Route::get('/', function () {
    return view('welcome');
});

// Language switcher
Route::get('/language/{locale}', function (string $locale) {
    if (!in_array($locale, ['en', 'km'])) {
        abort(400, __('messages.unsupported_language'));
    }

    Session::put('locale', $locale);
    app()->setLocale($locale);

    return redirect(url()->previous() ?: '/');
});

// google login routes
Route::get('/auth/google', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);
