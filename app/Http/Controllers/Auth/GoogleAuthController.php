<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    private const CODE_TTL_SECONDS = 120;

    public function redirect()
    {
        if (! Setting::value('user.google_login', true)) {
            return redirect()->away($this->callbackUrl(['error' => 'google_login_disabled']));
        }

        try {
            return Socialite::driver('google')
                ->stateless()
                ->with(['prompt' => 'select_account'])
                ->redirect();
        } catch (Throwable $e) {
            Log::error('Google OAuth redirect could not be started.', ['exception' => $e::class]);

            return redirect()->away($this->callbackUrl(['error' => 'google_auth_failed']));
        }
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            $googleId = trim((string) $googleUser->getId());
            $email = mb_strtolower(trim((string) $googleUser->getEmail()));
            $name = trim((string) $googleUser->getName());
            $raw = $googleUser->getRaw();

            $emailVerified = filter_var(data_get($raw, 'email_verified'), FILTER_VALIDATE_BOOLEAN);
            $valid = Validator::make([
                'google_id' => $googleId,
                'email' => $email,
                'name' => $name,
            ], [
                'google_id' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
            ])->passes();

            if (! $valid || ! $emailVerified) {
                Log::warning('Google OAuth returned invalid or unverified identity data.', [
                    'google_id_present' => $googleId !== '',
                    'email_present' => $email !== '',
                    'email_verified' => $emailVerified,
                ]);

                return redirect()->away($this->callbackUrl(['error' => 'invalid_google_account']));
            }

            $user = DB::transaction(function () use ($googleId, $email, $name, $googleUser) {
                $byGoogleId = User::where('google_id', $googleId)->lockForUpdate()->first();
                $byEmail = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();

                if ($byGoogleId && $byEmail && $byGoogleId->isNot($byEmail)) {
                    Log::warning('Google OAuth identity conflicts with an existing account.', [
                        'google_id_present' => true,
                        'email_account_conflict' => true,
                    ]);

                    return null;
                }

                $user = $byGoogleId ?: $byEmail;

                if (! $user) {
                    if (! Setting::value('user.registration_enabled', true)) {
                        return 'registration_disabled';
                    }

                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'google_id' => $googleId,
                        'avatar' => $googleUser->getAvatar(),
                        'password' => Hash::make(Str::random(48)),
                        'role' => Setting::value('user.default_role', 'customer'),
                        'status' => 'active',
                        'email_verified_at' => now(),
                    ]);
                } elseif (! $user->google_id) {
                    $user->google_id = $googleId;
                    $user->save();
                }

                return $user;
            });

            if ($user === 'registration_disabled') {
                return redirect()->away($this->callbackUrl(['error' => 'registration_disabled']));
            }

            if (! $user) {
                return redirect()->away($this->callbackUrl(['error' => 'google_account_unavailable']));
            }

            if ($user->status !== 'active') {
                Log::notice('Google OAuth login blocked for inactive account.', [
                    'user_id' => $user->id,
                    'status' => $user->status,
                ]);

                return redirect()->away($this->callbackUrl(['error' => 'account_inactive']));
            }

            $code = Str::random(64);
            Cache::put(
                $this->codeCacheKey($code),
                ['user_id' => $user->id],
                now()->addSeconds(self::CODE_TTL_SECONDS),
            );

            return redirect()->away($this->callbackUrl(['code' => $code]));
        } catch (QueryException $e) {
            // A concurrent first login can race on unique email/google_id constraints.
            Log::warning('Google OAuth account persistence failed.', ['exception' => $e::class]);

            return redirect()->away($this->callbackUrl(['error' => 'google_account_unavailable']));
        } catch (Throwable $e) {
            Log::error('Google OAuth callback failed.', ['exception' => $e::class]);

            return redirect()->away($this->callbackUrl(['error' => 'google_auth_failed']));
        }
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        $payload = Cache::pull($this->codeCacheKey($validated['code']));

        if (! is_array($payload) || ! isset($payload['user_id'])) {
            Log::notice('Google OAuth code exchange rejected.', ['reason' => 'invalid_or_expired_code']);

            return response()->json([
                'success' => false,
                'message' => 'This Google sign-in link is invalid or has expired. Please try again.',
            ], 422);
        }

        $user = User::find($payload['user_id']);

        if (! $user) {
            Log::notice('Google OAuth code exchange rejected.', ['reason' => 'user_not_found']);

            return response()->json([
                'success' => false,
                'message' => 'This Google sign-in link is invalid or has expired. Please try again.',
            ], 422);
        }

        if ($user->status !== 'active') {
            Log::notice('Google OAuth code exchange blocked for inactive account.', [
                'user_id' => $user->id,
                'status' => $user->status,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'This account is inactive. Please contact support.',
            ], 403);
        }

        try {
            $token = auth('api')->login($user);
        } catch (Throwable $e) {
            Log::error('Google OAuth token issuance failed.', [
                'exception' => $e::class,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to complete Google sign-in. Please try again.',
            ], 500);
        }

        if (! is_string($token) || $token === '') {
            Log::error('Google OAuth token issuance returned an empty token.', [
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to complete Google sign-in. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'user' => new UserResource($user->load('profile')),
            ],
        ]);
    }

    private function codeCacheKey(string $code): string
    {
        return 'google_oauth:code:'.hash('sha256', $code);
    }

    private function callbackUrl(array $params): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/').'/auth/google/callback';

        return $base.'?'.http_build_query($params);
    }
}
