<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(Request $request)
    {
        // Validate registration data
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|string|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        // Create the user.
        //
        // The password is automatically hashed because
        // User.php contains:
        //
        // 'password' => 'hashed'
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
        ]);

        // Generate JWT token

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'bearer',
        ], 201);
    }


    /**
     * Login user.
     */
    public function login(Request $request)
    {
        // Validate login data
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Attempt to authenticate
        $token = JWTAuth::attempt($credentials);

        // Wrong email/password
        if (!$token) {
            return response()->json([
                'message' => 'Invalid email or password',
            ], 401);
        }

        // Login successful
        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => JWTAuth::user(),
        ]);
    }


    /**
     * Get currently authenticated user.
     */
    public function me()
    {
        return response()->json([
            'user' => JWTAuth::user(),
        ]);
    }


    /**
     * Logout user.
     *
     * The current JWT token will be invalidated.
     */
    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }
}
