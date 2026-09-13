<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleAuthController;

Route::get('/', function () {
    return view('welcome');
});


// google login routes
Route::get('/auth/google', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

// Official Bakong Member KHQR Stand Preview
Route::get('/bakong-stand', function (\Illuminate\Http\Request $request) {
    return view('bakong-stand-preview', [
        'name' => $request->query('name', 'SOTHAT OUK'),
        'amount' => $request->query('amount'),
        'currency' => $request->query('currency', 'USD'),
        'account' => $request->query('account', 'sothat@bakong'),
        'qrString' => $request->query('qr'),
    ]);
});
