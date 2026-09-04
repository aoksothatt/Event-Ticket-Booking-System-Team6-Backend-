<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
        /**
         * Profile fields stored on the `profiles` table.
         */
        private const PROFILE_FIELDS = ['phone', 'gender', 'dob', 'address'];

        /**
         * Get the authenticated user's profile.
         */
        public function show(Request $request)
        {
            $user = $request->user()->load('profile');

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'profile' => $user->profile,
                ],
            ]);
        }

        /**
         * Update the authenticated user's profile.
         */
        public function update(Request $request)
        {
            $user = $request->user();

            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
                'phone' => ['nullable', 'string', 'max:20'],
                'gender' => ['nullable', 'string', 'in:male,female,other'],
                'dob' => ['nullable', 'date'],
                'address' => ['nullable', 'string', 'max:255'],
            ]);

            // Update columns that live on the `users` table.
            $userData = array_intersect_key($validated, array_flip(['name', 'email', 'phone']));
            $user->update($userData);

            // If the email changed, the new address must be verified again.
            if (isset($userData['email']) && $userData['email'] !== $user->getOriginal('email')) {
                $user->forceFill(['email_verified_at' => null])->save();
            }

            // Update columns that live on the `profiles` table (create row if missing).
            $profile = $user->profile()->firstOrNew();

            foreach (self::PROFILE_FIELDS as $field) {
                if (array_key_exists($field, $validated)) {
                    $profile->{$field} = $validated[$field];
                }
            }

            if ($profile->exists || $profile->isDirty()) {
                $profile->user_id = $user->id;
                $profile->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully.',
                'data' => $user->fresh()->load('profile'),
            ]);
        }

        /**
         * Change the authenticated user's password.
         */
        public function changePassword(Request $request)
        {
            $user = $request->user();

            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'new_password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if (! Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect.',
                ], 422);
            }

            $user->update(['password' => $validated['new_password']]);

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully.',
            ]);
        }

        /**
         * Upload a new avatar for the authenticated user.
         */
        public function uploadAvatar(Request $request)
        {
            $user = $request->user();

            $request->validate([
                'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            ]);

            $path = $request->file('avatar')->store('avatars', 'public');
            $url = Storage::disk('public')->url($path);

            $user->update(['avatar' => $path]);

            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                ['avatar' => $path]
            );

            return response()->json([
                'success' => true,
                'message' => 'Avatar uploaded successfully.',
                'data' => [
                    'avatar_path' => $path,
                    'avatar_url' => $url,
                ],
            ]);
        }
    }
