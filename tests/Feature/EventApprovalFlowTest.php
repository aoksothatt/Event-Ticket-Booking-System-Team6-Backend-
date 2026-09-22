<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Admin approval workflow.
 *
 * When `event.admin_approval_required` is enabled, organizer submissions are
 * saved as drafts and only an administrator may publish them. This covers the
 * backend enforcement (store/update), the new admin approve/reject endpoints,
 * the `rejected` status + rejection reason, and the `filter=pending` queue.
 */
class EventApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user->fresh());
    }

    private function enableApproval(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'event.admin_approval_required'],
            ['key' => 'event.admin_approval_required', 'value' => '1', 'type' => 'boolean', 'group' => 'event']
        );
    }

    private function makeOrganizer(): array
    {
        $organizerUser = User::factory()->create(['role' => 'organizer', 'status' => 'active']);
        $organizer = Organizer::create([
            'user_id' => $organizerUser->id,
            'company_name' => 'Approval Test Co.',
            'is_verified' => true,
        ]);
        $category = Category::create(['name' => 'Sports']);
        $venue = Venue::create([
            'name' => 'Olympic Stadium',
            'address' => '1 Main St',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'capacity' => 200,
        ]);

        return compact('organizerUser', 'organizer', 'category', 'venue');
    }

    private function eventPayload(array $seed, string $status = 'published'): array
    {
        return [
            'organizer_id' => $seed['organizer']->id,
            'category_id' => $seed['category']->id,
            'venue_id' => $seed['venue']->id,
            'title' => 'Approval Needed Event '.rand(1, 999999),
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'start_time' => '18:00',
            'end_time' => '22:00',
            'status' => $status,
        ];
    }

    public function test_organizer_event_is_forced_to_draft_when_approval_required(): void
    {
        $this->enableApproval();
        $seed = $this->makeOrganizer();

        $payload = $this->eventPayload($seed, 'published');

        $response = $this->withToken($this->tokenFor($seed['organizerUser']))
            ->postJson('/api/events', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('events', ['slug' => $response->json('data.slug'), 'status' => 'draft']);
    }

    public function test_organizer_cannot_publish_when_approval_required(): void
    {
        $this->enableApproval();
        $seed = $this->makeOrganizer();
        $event = Event::factory()->create([
            'organizer_id' => $seed['organizer']->id,
            'category_id' => $seed['category']->id,
            'venue_id' => $seed['venue']->id,
            'title' => 'Pending Event',
            'status' => 'draft',
        ]);

        $this->withToken($this->tokenFor($seed['organizerUser']))
            ->putJson("/api/events/{$event->id}", ['status' => 'published'])
            ->assertStatus(403);

        $this->assertSame('draft', $event->fresh()->status);
    }

    public function test_organizer_can_publish_when_approval_not_required(): void
    {
        $seed = $this->makeOrganizer();
        $event = Event::factory()->create([
            'organizer_id' => $seed['organizer']->id,
            'category_id' => $seed['category']->id,
            'venue_id' => $seed['venue']->id,
            'title' => 'Free Publish Event',
            'status' => 'draft',
        ]);

        $this->withToken($this->tokenFor($seed['organizerUser']))
            ->putJson("/api/events/{$event->id}", ['status' => 'published'])
            ->assertOk();

        $this->assertSame('published', $event->fresh()->status);
    }

    public function test_admin_approval_publishes_event_and_clears_rejection_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $seed = $this->makeOrganizer();
        $event = Event::factory()->create([
            'organizer_id' => $seed['organizer']->id,
            'category_id' => $seed['category']->id,
            'venue_id' => $seed['venue']->id,
            'title' => 'Approvable Event',
            'status' => 'draft',
            'rejection_reason' => 'Previously rejected due to a typo',
        ]);

        $this->withToken($this->tokenFor($admin))
            ->postJson("/api/admin/events/{$event->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $fresh = $event->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_admin_rejection_marks_event_rejected_with_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $seed = $this->makeOrganizer();
        $event = Event::factory()->create([
            'organizer_id' => $seed['organizer']->id,
            'category_id' => $seed['category']->id,
            'venue_id' => $seed['venue']->id,
            'title' => 'Rejectable Event',
            'status' => 'draft',
        ]);

        $this->withToken($this->tokenFor($admin))
            ->postJson("/api/admin/events/{$event->id}/reject", ['reason' => 'Banner does not match the event.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Banner does not match the event.');

        $fresh = $event->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Banner does not match the event.', $fresh->rejection_reason);
    }

    public function test_organizer_can_resubmit_rejected_event_as_draft(): void
    {
        $seed = $this->makeOrganizer();
        $event = Event::factory()->create([
            'organizer_id' => $seed['organizer']->id,
            'category_id' => $seed['category']->id,
            'venue_id' => $seed['venue']->id,
            'title' => 'Resubmission Event',
            'status' => 'rejected',
            'rejection_reason' => 'Fix the description.',
        ]);

        $this->withToken($this->tokenFor($seed['organizerUser']))
            ->putJson("/api/events/{$event->id}", ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_customer_cannot_approve_or_reject_events(): void
    {
        $seed = $this->makeOrganizer();
        $event = Event::factory()->create([
            'organizer_id' => $seed['organizer']->id,
            'category_id' => $seed['category']->id,
            'venue_id' => $seed['venue']->id,
            'title' => 'Protected Event',
            'status' => 'draft',
        ]);
        $customer = User::factory()->create(['role' => 'customer', 'status' => 'active']);

        $this->withToken($this->tokenFor($customer))
            ->postJson("/api/admin/events/{$event->id}/approve")
            ->assertStatus(403);

        $this->withToken($this->tokenFor($customer))
            ->postJson("/api/admin/events/{$event->id}/reject", ['reason' => 'nope'])
            ->assertStatus(403);

        $this->assertSame('draft', $event->fresh()->status);
    }

    public function test_pending_filter_returns_only_draft_events(): void
    {
        Event::factory()->create(['status' => 'draft']);
        Event::factory()->create(['status' => 'published']);
        $rejected = Event::factory()->create(['status' => 'rejected']);

        $ids = $this->getJson('/api/events?filter=pending')
            ->assertOk()
            ->json('data.data.*.id');

        $this->assertContains($rejected->id, Event::where('status', 'rejected')->pluck('id')->all());
        $this->assertNotContains($rejected->id, $ids, 'Rejected events must not appear in the pending queue.');
        $this->assertNotEmpty($ids, 'The pending queue must contain the draft event.');
    }

    public function test_rejected_events_are_not_publicly_visible(): void
    {
        Event::factory()->create(['status' => 'rejected']);

        $ids = $this->getJson('/api/events/upcoming')
            ->assertOk()
            ->json('data.data');

        $this->assertEmpty($ids);
    }
}