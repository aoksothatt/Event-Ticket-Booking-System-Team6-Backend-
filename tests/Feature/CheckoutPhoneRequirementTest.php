<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use App\Services\Bakong\BakongService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * "Require Phone Number" booking setting.
 *
 * When `booking.require_phone` is enabled the checkout API must reject orders
 * from buyers whose PROFILE phone (`users.phone`) is missing. A phone sent in
 * the checkout payload is deliberately NOT trusted or persisted — the buyer is
 * directed to update their profile instead. The public settings endpoint must
 * also expose the flag so the SPA can render the right checkout state.
 */
class CheckoutPhoneRequirementTest extends TestCase
{
    use RefreshDatabase;

    private function enablePhoneRequirement(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'booking.require_phone'],
            ['key' => 'booking.require_phone', 'value' => '1', 'type' => 'boolean', 'group' => 'booking']
        );
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user->fresh());
    }

    private function makeEventWithTicket(): array
    {
        $user = User::factory()->create(['role' => 'customer']);
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::create([
            'user_id' => $organizerUser->id,
            'company_name' => 'Phone Test Co.',
            'is_verified' => true,
        ]);
        $category = Category::create(['name' => 'Music']);
        $venue = Venue::create([
            'name' => 'National Stadium',
            'address' => '123 Main St',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'capacity' => 500,
        ]);
        $event = Event::create([
            'organizer_id' => $organizer->id,
            'category_id' => $category->id,
            'venue_id' => $venue->id,
            'title' => 'Phone Required Concert',
            'slug' => 'phone-concert-'.rand(1, 999999),
            'description' => 'A test event',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'start_time' => '19:00:00',
            'end_time' => '23:00:00',
            'status' => 'published',
        ]);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General Admission',
            'price' => 25.50,
            'quantity' => 100,
            'sold_quantity' => 0,
            'status' => 'active',
        ]);

        return compact('user', 'event', 'ticketType');
    }

    private function mockBakong(): void
    {
        $this->mock(BakongService::class)
            ->shouldReceive('generateKhqr')
            ->andReturn([
                'md5' => 'md5-phone-1',
                'khqr' => '000201010212phonekhr',
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ])
            ->shouldReceive('generateDeeplink')
            ->andReturn(null);
    }

    public function test_public_settings_expose_booking_require_phone(): void
    {
        $this->enablePhoneRequirement();

        $this->getJson('/api/settings/public')
            ->assertOk()
            ->assertJson(['data' => ['settings' => ['booking.require_phone' => true]]]);
    }

    public function test_checkout_requires_phone_when_setting_is_enabled(): void
    {
        $this->enablePhoneRequirement();
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'PHONE_REQUIRED']);
    }

    public function test_checkout_never_trusts_a_client_supplied_phone(): void
    {
        $this->enablePhoneRequirement();
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        // Even though the client sends a phone, the checkout must still fail:
        // the PROFILE phone is the only source of truth and nothing is
        // persisted to the user.
        $this->withToken($this->tokenFor($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 1,
                'phone' => '+855 12 345 678',
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'PHONE_REQUIRED']);

        $this->assertNull($user->fresh()->phone);
    }

    public function test_checkout_skips_phone_block_when_profile_already_has_phone(): void
    {
        $this->enablePhoneRequirement();
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();
        $user->update(['phone' => '+855 98 765 432']);
        $this->mockBakong();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 1,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        // Nothing new was written (and the profile phone is untouched).
        $this->assertSame('+855 98 765 432', $user->fresh()->phone);
    }

    public function test_checkout_works_without_phone_when_setting_is_disabled(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();
        $this->mockBakong();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 1,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertNull($user->fresh()->phone);
    }
}