<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Organizer;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): string
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);

        if ($role === 'organizer') {
            Organizer::factory()->create(['user_id' => $user->id]);
        }

        return JWTAuth::fromUser($user->fresh());
    }

    public function test_customer_cannot_access_admin_dashboard(): void
    {
        $token = $this->actingAsRole('customer');

        $this->withToken($token)
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_customer_cannot_access_organizer_routes(): void
    {
        $token = $this->actingAsRole('customer');

        $this->withToken($token)
            ->getJson('/api/organizer/dashboard')
            ->assertStatus(403);
    }

    public function test_customer_cannot_access_admin_users(): void
    {
        $token = $this->actingAsRole('customer');

        $this->withToken($token)
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $token = $this->actingAsRole('admin');

        $this->withToken($token)
            ->getJson('/api/admin/dashboard')
            ->assertOk();
    }

    public function test_organizer_can_access_organizer_dashboard(): void
    {
        $token = $this->actingAsRole('organizer');

        $this->withToken($token)
            ->getJson('/api/organizer/dashboard')
            ->assertOk();
    }

    public function test_event_staff_cannot_create_events(): void
    {
        $token = $this->actingAsRole('event_staff');

        $this->withToken($token)
            ->postJson('/api/organizer/events', ['title' => 'Blocked'])
            ->assertStatus(403);
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/admin/dashboard')
            ->assertStatus(401);
    }

    public function test_organizer_cannot_modify_another_organizers_staff_assignment(): void
    {
        $ownerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::factory()->create(['user_id' => $ownerUser->id]);

        $otherOwnerUser = User::factory()->create(['role' => 'organizer']);
        $otherOrganizer = Organizer::factory()->create(['user_id' => $otherOwnerUser->id]);

        // $otherOrganizer assigned a staff member.
        $staffUser = User::factory()->create(['role' => 'event_staff']);
        $assignment = EventStaff::create([
            'user_id' => $staffUser->id,
            'organizer_id' => $otherOrganizer->id,
        ]);

        $token = JWTAuth::fromUser($ownerUser);

        // Listing ignores the forged organizer_id and returns only own staff.
        $this->withToken($token)
            ->getJson('/api/organizer/staff?organizer_id='.$otherOrganizer->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        // Modifying the other organizer's assignment is not found.
        $this->withToken($token)
            ->putJson('/api/organizer/staff/'.$assignment->id, ['is_active' => false])
            ->assertStatus(404);
    }

    public function test_admin_can_moderate_another_users_review(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $event = Event::factory()->create(['status' => 'published']);
        $review = Review::create([
            'event_id' => $event->id,
            'user_id' => $customer->id,
            'rating' => 5,
            'comment' => 'Great event',
            'status' => 'active',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::fromUser($admin);

        // Admin can publish a review written by another user (Reviews.vue).
        $this->withToken($token)
            ->putJson("/api/reviews/{$review->id}", ['status' => 'published'])
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'status' => 'published']);

        // Admin can delete any review.
        $this->withToken($token)
            ->deleteJson("/api/reviews/{$review->id}")
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_customer_cannot_moderate_another_users_review(): void
    {
        $owner = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $event = Event::factory()->create(['status' => 'published']);
        $review = Review::create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'rating' => 4,
            'comment' => 'Nice',
            'status' => 'active',
        ]);

        $token = JWTAuth::fromUser($other);

        $this->withToken($token)
            ->putJson("/api/reviews/{$review->id}", ['status' => 'published'])
            ->assertStatus(404);

        $this->withToken($token)
            ->deleteJson("/api/reviews/{$review->id}")
            ->assertStatus(404);
    }

    public function test_non_admin_cannot_delete_booking(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);
        $booking = Booking::factory()->create(['user_id' => $customer->id]);
        $token = JWTAuth::fromUser($customer);

        $this->withToken($token)
            ->deleteJson("/api/bookings/{$booking->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('Booking', ['id' => $booking->id]);
    }

    public function test_admin_can_delete_booking(): void
    {
        $booking = Booking::factory()->create();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = JWTAuth::fromUser($admin);

        $this->withToken($token)
            ->deleteJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('Booking', ['id' => $booking->id]);
    }
}
