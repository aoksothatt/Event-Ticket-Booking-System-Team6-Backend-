<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UpcomingEventTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        return JWTAuth::fromUser($admin->fresh());
    }

    public function test_admin_can_mark_event_as_upcoming_and_remove_it(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'banner' => 'events/football.jpg']);

        $this->assertFalse((bool) $event->is_upcoming);

        // Mark as upcoming.
        $this->withToken($this->adminToken())
            ->patchJson("/api/admin/events/{$event->id}/upcoming", ['is_upcoming' => true])
            ->assertOk()
            ->assertJsonPath('data.is_upcoming', true);

        $this->assertTrue((bool) $event->fresh()->is_upcoming);

        // Banner still returned (image functionality untouched).
        $this->withToken($this->adminToken())
            ->patchJson("/api/admin/events/{$event->id}/upcoming", ['is_upcoming' => true])
            ->assertOk()
            ->assertJsonPath('data.banner', 'events/football.jpg');

        // Toggle off.
        $this->withToken($this->adminToken())
            ->patchJson("/api/admin/events/{$event->id}/upcoming", ['is_upcoming' => false])
            ->assertOk()
            ->assertJsonPath('data.is_upcoming', false);

        $this->assertFalse((bool) $event->fresh()->is_upcoming);
    }

    public function test_flagged_upcoming_event_appears_in_upcoming_api(): void
    {
        $event = Event::factory()->create(['status' => 'published']);

        $this->withToken($this->adminToken())
            ->patchJson("/api/admin/events/{$event->id}/upcoming", ['is_upcoming' => true])
            ->assertOk();

        $this->getJson('/api/events/upcoming')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $event->id);

        // Toggled off means the banner no longer includes the event.
        $this->withToken($this->adminToken())
            ->patchJson("/api/admin/events/{$event->id}/upcoming", ['is_upcoming' => false])
            ->assertOk();

        $this->getJson('/api/events/upcoming')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    public function test_draft_event_never_appears_in_upcoming_api(): void
    {
        $event = Event::factory()->create(['status' => 'draft']);

        $this->withToken($this->adminToken())
            ->patchJson("/api/admin/events/{$event->id}/upcoming", ['is_upcoming' => true])
            ->assertOk();

        $this->getJson('/api/events/upcoming')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    public function test_customer_cannot_toggle_upcoming(): void
    {
        $event = Event::factory()->create(['status' => 'published']);

        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $token = JWTAuth::fromUser($customer->fresh());

        $this->withToken($token)
            ->patchJson("/api/admin/events/{$event->id}/upcoming", ['is_upcoming' => true])
            ->assertStatus(403);

        $this->assertFalse((bool) $event->fresh()->is_upcoming);
    }
}
