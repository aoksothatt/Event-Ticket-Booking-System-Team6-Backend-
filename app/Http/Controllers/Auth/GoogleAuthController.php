<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        // Platform-level switch: when Google login is disabled the browser is
        // bounced straight back to the SPA with an error flag.
        if (! Setting::value('user.google_login', true)) {
            return redirect()->away($this->callbackUrl(['error' => 'google_login_disabled']));
        }

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
                // Signing in via Google also creates a brand-new account, so it
                // respects the same registration switch as the local register
                // flow. Google-verified emails count as verified immediately.
                if (! Setting::value('user.registration_enabled', true)) {
                    return redirect()->away($this->callbackUrl(['error' => 'registration_disabled']));
                }

                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                    'password' => Hash::make(Str::random(32)),
                    'role' => Setting::value('user.default_role', 'customer'),
                    'status' => 'active',
                    'email_verified_at' => now(),
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
