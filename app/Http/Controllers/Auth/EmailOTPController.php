<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationOTP;
use App\Models\PasswordResetOTP;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;


class EmailOTPController extends Controller
{


    public function forgetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No account found with this email.',
            ], 404);
        }

        // Generate 6-digit OTP
        $otp = random_int(100000, 999999);

        // Delete previous OTP
        PasswordResetOTP::where('email', $user->email)->delete();

        // Store hashed OTP
        PasswordResetOTP::create([
            'email' => $user->email,
            'otp' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
        ]);

        // Send email to user
        Mail::to($user->email)->send(
            new EmailVerificationOTP($otp, $user->name)
        );

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully.',
            'data' => [
                'email' => $user->email,
                'expires_in' => 300,
            ],
        ]);
    }

    //user verify OTP
    public function verifyOTP(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $otpRecord = PasswordResetOTP::where(
            'email',
            $validated['email']
        )
            ->latest()
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'OTP not found. Please request a new OTP.',
            ], 422);
        }

        // Check expiration
        if (now()->greaterThan($otpRecord->expires_at)) {

            $otpRecord->delete();

            return response()->json([
                'success' => false,
                'message' => 'OTP has expired. Please request a new OTP.',
            ], 422);
        }

        // Check OTP
        if (!Hash::check($validated['otp'], $otpRecord->otp)) {

            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP.',
            ], 422);
        }

        // OTP correct
        $otpRecord->delete();

        // Create temporary reset token
        $resetToken = bin2hex(random_bytes(32));

        //put(key,value,expires)

        Cache::put(
            'password_reset:' . $resetToken,
            $validated['email'],
            now()->addMinutes(5)           //5 minute
        );

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully.',
            'data' => [
                'reset_token' => $resetToken,
                'expires_in' => 600,
            ],
        ]);
    }

    //Reset password
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'reset_token' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed'
            ],
        ]);

        $email = Cache::get(
            'password_reset:' . $validated['reset_token']
        );

        if (!$email) {
            return response()->json([
                'success' => false,
                'message' => 'Reset token is invalid or expired.',
            ], 422);
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        // Delete token after successful reset
        Cache::forget(
            'password_reset:' . $validated['reset_token']
        );

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully.',
        ]);
    }
}
