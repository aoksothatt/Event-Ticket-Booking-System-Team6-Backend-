<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class EventRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private function customerToken(): string
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);

        return JWTAuth::fromUser($customer->fresh());
    }

    public function test_guest_can_request_recommendations_without_authentication(): void
    {
        $target = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(12)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(),
        ]);

        $this->getJson("/api/events/{$target->id}/recommendations")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_current_event_never_appears_in_its_own_recommendations(): void
    {
        $target = Event::factory()->create(['status' => 'published']);

        Event::factory()->count(4)->create(['status' => 'published']);

        $ids = $this->getJson("/api/events/{$target->id}/recommendations")
            ->assertOk()
            ->json('data.*.id');

        $this->assertNotContains($target->id, $ids);
    }

    public function test_same_category_ranked_first_and_invalid_events_excluded(): void
    {
        $target = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(20)->toDateString(),
        ]);

        // Directly related: same category → must be the top recommendation.
        $sameCategory = Event::factory()->create([
            'status' => 'published',
            'category_id' => $target->category_id,
            'start_date' => now()->addDays(21)->toDateString(),
            'end_date' => now()->addDays(21)->toDateString(),
        ]);

        // These must never surface.
        Event::factory()->create([
            'status' => 'draft',
            'category_id' => $target->category_id,
            'end_date' => now()->addDays(30)->toDateString(),
        ]);
        Event::factory()->create([
            'status' => 'cancelled',
            'category_id' => $target->category_id,
            'end_date' => now()->addDays(30)->toDateString(),
        ]);
        Event::factory()->create([
            'status' => 'published',
            'category_id' => $target->category_id,
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $data = $this->getJson("/api/events/{$target->id}/recommendations")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data);
        $this->assertEquals($sameCategory->id, $data[0]['id']);

        foreach ($data as $row) {
            $this->assertNotEquals($target->id, $row['id']);
        }
    }

    public function test_popular_event_outranks_unrelated_event(): void
    {
        $target = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);

        $unrelated = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);

        $popular = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);

        Booking::factory()->count(5)->create(['event_id' => $popular->id, 'status' => 'confirmed']);

        $data = $this->getJson("/api/events/{$target->id}/recommendations")
            ->assertOk()
            ->json('data');

        $ids = array_column($data, 'id');
        $this->assertContains($popular->id, $ids);
        $this->assertContains($unrelated->id, $ids);
        $this->assertLessThan(array_search($unrelated->id, $ids), array_search($popular->id, $ids));
    }

    public function test_authenticated_user_gets_affinity_boost_from_favorited_categories(): void
    {
        $target = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $user = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $favCategory = Category::factory()->create();

        $favorited = Event::factory()->create([
            'status' => 'published',
            'category_id' => $favCategory->id,
            'start_date' => now()->addDays(11)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
        ]);
        $user->favorites()->attach($favorited->id);

        // Same timing, one in the user's favorite category and one elsewhere.
        Event::factory()->create([
            'status' => 'published',
            'category_id' => $favCategory->id,
            'start_date' => now()->addDays(12)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(),
        ]);
        $other = Event::factory()->create([
            'status' => 'published',
            'start_date' => now()->addDays(12)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(),
        ]);

        $data = $this->withToken(JWTAuth::fromUser($user->fresh()))
            ->getJson("/api/events/{$target->id}/recommendations")
            ->assertOk()
            ->json('data');

        // The event inside the user's favorited category must outrank the
        // unrelated event (guest ordering would leave them tied).
        $this->assertNotEquals($other->id, $data[0]['id']);

        $ids = array_column($data, 'id');
        $this->assertNotContains($target->id, $ids);
    }
}