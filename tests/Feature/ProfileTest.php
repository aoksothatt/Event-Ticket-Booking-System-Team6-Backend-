<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_show_returns_user_with_profile(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/profile');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', $user->name)
            ->assertJsonPath('data.profile.user_id', $user->id);
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    public function test_update_profile(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->putJson('/api/profile', [
                'name' => 'New Name',
                'phone' => '012345678',
                'gender' => 'male',
                'dob' => '2000-01-01',
                'address' => 'Phnom Penh',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'gender' => 'male',
            'dob' => '2000-01-01',
            'address' => 'Phnom Penh',
        ]);
    }

    public function test_update_profile_creates_profile_row_when_missing(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $user->profile()->delete();

        $this->withHeaders($this->authHeaders($user))
            ->putJson('/api/profile', ['gender' => 'female'])
            ->assertOk();

        $this->assertDatabaseHas('profiles', ['user_id' => $user->id, 'gender' => 'female']);
    }

    public function test_change_password(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'password' => 'oldpassword']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/profile/change-password', [
                'current_password' => 'oldpassword',
                'new_password' => 'newpassword123',
                'new_password_confirmation' => 'newpassword123',
            ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'password' => 'oldpassword']);

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/profile/change-password', [
                'current_password' => 'wrong',
                'new_password' => 'newpassword123',
                'new_password_confirmation' => 'newpassword123',
            ])
            ->assertStatus(422);
    }

    public function test_upload_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'customer']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/profile/avatar', [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $path = $response->json('data.avatar_path');
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => $path]);
    }
}
