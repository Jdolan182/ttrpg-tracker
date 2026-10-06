<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Creature;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * What an account can do before its email is verified: use the tracker and compendium like a guest,
 * and its settings, but nothing that saves.
 */
class EmailVerificationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_up_sends_the_verification_email()
    {
        Notification::fake();

        $this->post('/register', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password']);

        Notification::assertSentTo(User::where('email', 'new@example.com')->sole(), VerifyEmail::class);
    }

    public function test_unverified_accounts_can_look_around()
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/')->assertOk();
        $this->actingAs($user)->get('/compendium')->assertOk();
        $this->actingAs($user)->get('/settings/profile')->assertOk();
    }

    public function test_unverified_accounts_cant_save_anything()
    {
        $user = User::factory()->unverified()->create();
        $campaign = Campaign::factory()->create();
        $notice = route('verification.notice');

        $this->actingAs($user)->post('/encounters', ['name' => 'Ambush', 'round' => 0, 'activeIndex' => 0, 'combatants' => []])->assertRedirect($notice);
        $this->actingAs($user)->get('/compendium/create')->assertRedirect($notice);
        $this->actingAs($user)->post('/campaigns', ['name' => 'Mine'])->assertRedirect($notice);
        $this->actingAs($user)->post("/join/{$campaign->invite_token}")->assertRedirect($notice);

        $this->assertSame(0, $user->encounters()->count());
        $this->assertSame(0, Creature::where('user_id', $user->id)->count());
        $this->assertFalse($campaign->hasPlayer($user));
    }

    public function test_the_link_brings_an_invited_player_back_to_the_invite()
    {
        $user = User::factory()->unverified()->create();
        $campaign = Campaign::factory()->create();
        $invite = route('campaigns.join', $campaign->invite_token);

        $this->actingAs($user)->get($invite)->assertOk()->assertSessionHas('url.intended', $invite);

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($link)->assertRedirect($invite);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_changing_email_needs_verifying_again()
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => 'moved@example.com'])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_keeping_the_same_email_doesnt_resend()
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', ['name' => 'Renamed', 'email' => $user->email]);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }
}
