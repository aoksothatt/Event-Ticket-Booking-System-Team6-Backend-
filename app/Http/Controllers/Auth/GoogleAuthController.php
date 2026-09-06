<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')
            ->stateless()
            ->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();

            $user = User::where('google_id', $googleUser->getId())
                ->orWhere('email', $googleUser->getEmail())
                ->first();

            if (!$user) {
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'customer',
                    'status' => 'active',
                ]);
            } else {
                if (!$user->google_id) {
                    $user->update([
                        'google_id' => $googleUser->getId(),
                    ]);
                }
            }

            $token = auth('api')->login($user);

            // Redirect the browser back to the Vue SPA with the JWT as a
            // query parameter. The frontend reads it, stores it, and only
            // then navigates to the home page — the browser never stays on
            // this Laravel URL.
            return redirect()->away($this->callbackUrl(['token' => $token]));
        } catch (\Exception $e) {
            // Send the user back to the frontend with an error flag so the
            // Vue callback page can show a friendly message instead of a
            // raw Laravel JSON/error page.
            return redirect()->away($this->callbackUrl(['error' => 'google_auth_failed']));
        }
    }

    /**
     * Build the frontend's OAuth callback URL, preserving any extra query
     * parameters (e.g. token, error).
     */
    private function callbackUrl(array $params)
    {
        $base = rtrim((string) config('app.frontend_url'), '/') . '/auth/google/callback';

        return $base . '?' . http_build_query($params);
    }
}
