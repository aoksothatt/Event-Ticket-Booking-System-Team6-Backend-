<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * "Upcoming" is derived from the event's published status + start date
 * (there is no manual upcoming flag), and `filter=trending|upcoming` on the
 * events index must match the public /events/trending and /events/upcoming
 * endpoints. Trending itself stays a manual admin selection.
 */
class EventStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        return JWTAuth::fromUser($admin->fresh());
    }

    public function test_is_upcoming_is_computed_from_status_and_start_date(): void
    {
        $future = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(5)->toDateString(),
        ]);
        $past = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->subDays(5)->toDateString(),
        ]);
        $draft = Event::factory()->create([
            'status' => 'draft',
            'start_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->assertTrue((bool) $future->fresh()->is_upcoming);
        $this->assertFalse((bool) $past->fresh()->is_upcoming);
        $this->assertFalse((bool) $draft->fresh()->is_upcoming);
    }

    public function test_upcoming_endpoint_only_returns_published_future_events(): void
    {
        Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(3)->toDateString(),
        ]);
        Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->subDays(3)->toDateString(),
        ]);
        Event::factory()->create([
            'status' => 'draft',
            'start_date' => now()->addDays(3)->toDateString(),
        ]);
        Event::factory()->create([
            'status' => 'cancelled',
            'start_date' => now()->addDays(3)->toDateString(),
        ]);

        $response = $this->getJson('/api/events/upcoming')->assertOk();

        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_event_index_serializes_computed_upcoming_flag(): void
    {
        $future = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->getJson('/api/events?per_page=100')
            ->assertOk()
            ->assertJsonFragment(['id' => $future->id, 'is_upcoming' => true]);
    }

    public function test_filter_trending_returns_only_active_trending_events(): void
    {
        $trending = Event::factory()->create([
            'status' => 'published',
            'is_trending' => true,
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
        ]);
        Event::factory()->create(['status' => 'published', 'is_trending' => false]);
        Event::factory()->create([
            'status' => 'published',
            'is_trending' => true,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(5)->toDateString(),
        ]);

        $ids = $this->getJson('/api/events?filter=trending')
            ->assertOk()
            ->json('data.data.*.id');

        $this->assertContains($trending->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_filter_upcoming_uses_same_rule_as_upcoming_endpoint(): void
    {
        $future = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(6)->toDateString(),
        ]);
        Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->subDays(6)->toDateString(),
        ]);
        Event::factory()->create([
            'status' => 'draft',
            'start_date' => now()->addDays(6)->toDateString(),
        ]);

        $ids = $this->getJson('/api/events?filter=upcoming')
            ->assertOk()
            ->json('data.data.*.id');

        $this->assertContains($future->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_filter_all_returns_events_of_every_status(): void
    {
        Event::factory()->create(['status' => 'published']);
        Event::factory()->create(['status' => 'draft']);
        Event::factory()->create(['status' => 'cancelled']);

        $ids = $this->getJson('/api/events?filter=all&per_page=100')
            ->assertOk()
            ->json('data.data.*.id');

        $this->assertCount(3, $ids);
    }

    public function test_invalid_filter_value_is_rejected(): void
    {
        $this->getJson('/api/events?filter=weekend')->assertStatus(422);
    }

    public function test_admin_can_toggle_trending_and_customer_cannot(): void
    {
        $event = Event::factory()->create(['status' => 'published']);

        $this->withToken($this->adminToken())
            ->patchJson("/api/admin/events/{$event->id}/trending", ['is_trending' => true])
            ->assertOk()
            ->assertJsonPath('data.is_trending', true);

        $this->assertTrue((bool) $event->fresh()->is_trending);

        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $this->withToken(JWTAuth::fromUser($customer->fresh()))
            ->patchJson("/api/admin/events/{$event->id}/trending", ['is_trending' => false])
            ->assertStatus(403);
    }
}