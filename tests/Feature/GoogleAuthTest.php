<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_login_issues_a_jwt_for_an_active_user(): void
    {
        $this->mockGoogleIdentity('google-123', 'google@example.com');

        $response = $this->get('/auth/google/callback');
        $response->assertRedirectContains('/auth/google/callback?token=');

        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['token'] ?? null);
        $this->assertDatabaseHas('users', [
            'google_id' => 'google-123',
            'email' => 'google@example.com',
            'status' => 'active',
        ]);

        $this->withToken($query['token'])->getJson('/api/auth/me')->assertOk();
    }

    public function test_google_login_blocks_inactive_accounts_without_a_token(): void
    {
        User::factory()->create([
            'email' => 'blocked@example.com',
            'google_id' => 'google-blocked',
            'status' => 'suspended',
        ]);
        $this->mockGoogleIdentity('google-blocked', 'blocked@example.com');

        $response = $this->get('/auth/google/callback');
        $response->assertRedirectContains('error=account_inactive');
        $this->assertStringNotContainsString('token=', $response->headers->get('Location'));
    }

    public function test_google_login_rejects_unverified_email(): void
    {
        $this->mockGoogleIdentity('google-unverified', 'unverified@example.com', false);

        $this->get('/auth/google/callback')
            ->assertRedirectContains('error=invalid_google_account');
        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
    }

    public function test_google_login_rejects_google_id_and_email_conflict(): void
    {
        User::factory()->create(['email' => 'first@example.com', 'google_id' => 'google-first']);
        User::factory()->create(['email' => 'second@example.com', 'google_id' => 'google-second']);
        $this->mockGoogleIdentity('google-first', 'second@example.com');

        $this->get('/auth/google/callback')
            ->assertRedirectContains('error=google_account_unavailable');
        $this->assertDatabaseCount('users', 2);
    }

    private function mockGoogleIdentity(string $id, string $email, bool $verified = true): void
    {
        $googleUser = Mockery::mock(GoogleUser::class);
        $googleUser->shouldReceive('getId')->andReturn($id);
        $googleUser->shouldReceive('getEmail')->andReturn($email);
        $googleUser->shouldReceive('getName')->andReturn('Google Test User');
        $googleUser->shouldReceive('getAvatar')->andReturn(null);
        $googleUser->shouldReceive('getRaw')->andReturn(['email_verified' => $verified]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
