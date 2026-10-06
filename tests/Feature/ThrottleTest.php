<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rate limits: sign-ups and reset emails per address, and changes per account. Hitting one
 * from a page comes back as a readable error, not a bare 429.
 */
class ThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const INERTIA = ['X-Inertia' => 'true'];

    public function test_one_address_can_only_sign_up_a_few_times_an_hour()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/register', [
                'name' => "Player {$i}",
                'email' => "player{$i}@example.com",
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
            auth()->logout();
        }
        $this->assertSame(5, User::count());

        $this->from('/register')->withHeaders(self::INERTIA)->post('/register', [
            'name' => 'One too many',
            'email' => 'sixth@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/register')->assertSessionHasErrors('email');

        $this->assertSame(5, User::count());
        $this->assertStringContainsString('minutes', session('errors')->first('email'));
    }

    public function test_reset_emails_are_limited_per_address()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/forgot-password', ['email' => "someone{$i}@example.com"])->assertSessionHasNoErrors();
        }

        $this->from('/forgot-password')->withHeaders(self::INERTIA)
            ->post('/forgot-password', ['email' => 'someone6@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email');
    }

    public function test_an_account_making_changes_too_fast_is_slowed_down_but_can_still_read()
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 60; $i++) {
            $this->actingAs($user)->post('/campaigns', ['name' => "Campaign {$i}"]);
        }

        $this->actingAs($user)->from('/campaigns')->withHeaders(self::INERTIA)
            ->post('/campaigns', ['name' => 'One too many'])
            ->assertRedirect('/campaigns')
            ->assertSessionHasErrors('throttle');
        $this->assertDatabaseMissing('campaigns', ['name' => 'One too many']);

        // Reading pages doesn't count, and other accounts aren't affected.
        $this->flushHeaders();
        $this->actingAs($user)->get('/campaigns')->assertOk();
        $this->actingAs(User::factory()->create())->post('/campaigns', ['name' => 'Someone else'])->assertSessionHasNoErrors();
    }

    public function test_the_live_player_view_keeps_its_own_higher_limit()
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user, 'owner')->create();

        for ($i = 1; $i <= 61; $i++) {
            $this->actingAs($user)->putJson("/campaigns/{$campaign->id}/combat", [])->assertStatus(422);
        }
    }
}
