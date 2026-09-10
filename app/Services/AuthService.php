<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\AuthenticationException as CustomAuthException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;

class AuthService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Register a new customer account and issue a JWT.
     *
     * @return array{token: string, user: User}
     */
    public function register(string $name, string $email, string $password): array
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => Role::CUSTOMER->value,
            'status' => 'active',
        ]);

        $token = Auth::guard('api')->login($user);

        $this->activityLog->log(
            'auth.register',
            'New customer account registered',
            $user->id,
        );

        return ['token' => $token, 'user' => $user->fresh()];
    }

    /**
     * Authenticate credentials and issue a JWT.
     *
     * @return array{token: string, user: User}
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password): array
    {
        $credentials = ['email' => $email, 'password' => $password];

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            $user = User::where('email', $email)->first();

            $this->activityLog->log(
                'auth.login.failed',
                'Failed login attempt',
                $user?->id,
                metadata: ['email' => $email],
            );

            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $user = Auth::guard('api')->user();

        if ($user->status !== 'active') {
            Auth::guard('api')->logout();

            $this->activityLog->log(
                'auth.login.inactive',
                'Login blocked for inactive account',
                $user->id,
            );

            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        $this->activityLog->log(
            'auth.login',
            'User logged in',
            $user->id,
        );

        return ['token' => $token, 'user' => $user->fresh()];
    }

    /**
     * Blacklist the current token.
     */
    public function logout(User $user): void
    {
        $this->activityLog->log('auth.logout', 'User logged out', $user->id);

        Auth::guard('api')->logout();
    }

    /**
     * Refresh the current JWT.
     *
     * @return array{token: string, user: User}
     *
     * @throws CustomAuthException
     */
    public function refresh(): array
    {
        try {
            $token = Auth::guard('api')->refresh();
            $user = Auth::guard('api')->setToken($token)->user();
        } catch (TokenExpiredException $e) {
            throw new CustomAuthException('Token has expired. Please log in again.');
        } catch (\Throwable $e) {
            throw new CustomAuthException('Unable to refresh token. Please log in again.');
        }

        return ['token' => $token, 'user' => $user];
    }
}
