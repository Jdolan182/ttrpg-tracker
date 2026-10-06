<?php

namespace Tests\Feature\Settings;

use App\Models\Campaign;
use App\Models\Creature;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_deleting_an_account_takes_everything_it_made_but_leaves_other_peoples_things()
    {
        $user = User::factory()->create();
        Creature::factory()->count(2)->for($user)->create();
        Encounter::factory()->for($user)->create();
        $ownCampaign = Campaign::factory()->for($user, 'owner')->create(['live' => ['name' => 'Fight', 'round' => 1, 'activeIndex' => 0, 'combatants' => []]]);
        $player = User::factory()->create();
        $ownCampaign->players()->attach($player);

        // Somebody else's campaign the user plays in, with a character the user had claimed.
        $otherCampaign = Campaign::factory()->create();
        $theirCharacter = Creature::factory()->for($otherCampaign->owner)->create(['kind' => 'player', 'campaign_id' => $otherCampaign->id]);
        $otherCampaign->players()->attach($user, ['character_id' => $theirCharacter->id]);
        $srd = Creature::factory()->srd()->create();

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasNoErrors();

        $this->assertSame(0, Creature::where('user_id', $user->id)->count());
        $this->assertSame(0, Encounter::where('user_id', $user->id)->count());
        $this->assertNull($ownCampaign->fresh());
        $this->assertDatabaseMissing('campaign_user', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('campaign_user', ['campaign_id' => $ownCampaign->id]);

        // The player of the deleted campaign, the other DM's campaign and character, and the SRD are untouched.
        $this->assertNotNull($player->fresh());
        $this->assertNotNull($otherCampaign->fresh());
        $this->assertSame($otherCampaign->id, $theirCharacter->fresh()->campaign_id);
        $this->assertNotNull($srd->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->fresh());
    }
}
