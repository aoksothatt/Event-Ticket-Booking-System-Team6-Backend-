<?php

namespace Tests\Feature;

use App\Models\EventStaff;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonStructure(['data' => ['access_token', 'user', 'expires_in']]);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'role' => 'customer',
        ]);
    }

    public function test_customer_can_login(): void
    {
        $password = 'password123';
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => bcrypt($password),
            'role' => 'customer',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'customer@example.com',
            'password' => $password,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'user']]);
    }

    public function test_organizer_can_login(): void
    {
        $password = 'password123';
        $user = User::factory()->create([
            'email' => 'organizer@example.com',
            'password' => bcrypt($password),
            'role' => 'organizer',
            'status' => 'active',
        ]);
        Organizer::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'organizer@example.com',
            'password' => $password,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'organizer');
    }

    public function test_staff_can_login(): void
    {
        $password = 'password123';
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => bcrypt($password),
            'role' => 'event_staff',
            'status' => 'active',
        ]);
        $organizer = Organizer::factory()->create();
        EventStaff::create([
            'user_id' => $user->id,
            'organizer_id' => $organizer->id,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'staff@example.com',
            'password' => $password,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.role', 'event_staff');
    }

    public function test_admin_can_login(): void
    {
        $password = 'password123';
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt($password),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => $password,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_login_with_invalid_credentials_fails(): void
    {
        User::factory()->create(['email' => 'exists@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'exists@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_with_inactive_account_fails(): void
    {
        $password = 'password123';
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => bcrypt($password),
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'inactive@example.com',
            'password' => $password,
        ]);

        $response->assertStatus(422);
    }

    public function test_authenticated_user_can_fetch_me(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_authenticated_user_can_refresh_token(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'user']]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
