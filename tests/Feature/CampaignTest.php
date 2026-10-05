<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Creature;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Small numbers so the tests don't depend on whatever the real config says.
        config()->set('plans.plans.free.limits.campaigns', 1);
        config()->set('plans.plans.free.limits.campaign_players', 2);
        config()->set('plans.plans.free.limits.campaigns_joined', 1);
    }

    private function character(User $owner, ?Campaign $campaign = null): Creature
    {
        return Creature::factory()->for($owner)->create(['kind' => 'player', 'campaign_id' => $campaign?->id]);
    }

    private function join(Campaign $campaign, User $player, ?Creature $character = null): void
    {
        $campaign->players()->attach($player, ['character_id' => $character?->id]);
    }

    public function test_creating_a_campaign()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/campaigns', ['name' => 'Crimson Keep', 'description' => 'Bring rope.']);

        $campaign = $user->campaigns()->sole();
        $response->assertRedirect(route('campaigns.show', $campaign));
        $this->assertSame('Crimson Keep', $campaign->name);
        $this->assertSame('bands', $campaign->fresh()->enemy_hp);
        $this->assertSame(40, strlen($campaign->invite_token));
    }

    public function test_creating_campaigns_stops_at_the_limit()
    {
        $user = User::factory()->create();
        Campaign::factory()->for($user, 'owner')->create();

        $this->actingAs($user)->post('/campaigns', ['name' => 'Another'])->assertSessionHasErrors('limit');
        $this->assertSame(1, $user->campaigns()->count());
    }

    public function test_guests_are_sent_to_log_in()
    {
        $campaign = Campaign::factory()->create();

        $this->get('/campaigns')->assertRedirect('/login');
        $this->get("/campaigns/{$campaign->id}")->assertRedirect('/login');
    }

    public function test_the_dm_sees_everything_needed_to_run_it()
    {
        $campaign = Campaign::factory()->create();
        $dm = $campaign->owner;
        $hero = $this->character($dm, $campaign);
        $player = User::factory()->create(['name' => 'Pat']);
        $this->join($campaign, $player, $hero);
        Encounter::factory()->for($dm)->create(['campaign_id' => $campaign->id]);
        $loose = Encounter::factory()->for($dm)->create();
        $spare = $this->character($dm);

        $this->actingAs($dm)->get("/campaigns/{$campaign->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Show')
                ->where('campaign.isDm', true)
                ->where('campaign.inviteUrl', route('campaigns.join', $campaign->invite_token))
                ->where('party.0.claimedBy', 'Pat')
                ->has('players', 1)
                ->has('encounters', 1)
                ->where('availableEncounters.0.id', $loose->id)
                ->where('availableCharacters.0.id', $spare->id)
            );
    }

    public function test_players_see_the_campaign_without_the_dms_tools()
    {
        $campaign = Campaign::factory()->create();
        $hero = $this->character($campaign->owner, $campaign);
        $player = User::factory()->create();
        $this->join($campaign, $player, $hero);
        Encounter::factory()->for($campaign->owner)->create(['campaign_id' => $campaign->id]);

        $this->actingAs($player)->get("/campaigns/{$campaign->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.isDm', false)
                ->where('campaign.inviteUrl', null)
                ->where('myCharacterId', $hero->id)
                ->has('party', 1)
                ->has('encounters', 0)
                ->has('availableEncounters', 0)
                ->has('availableCharacters', 0)
            );
    }

    public function test_strangers_cant_see_or_change_a_campaign()
    {
        $campaign = Campaign::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/campaigns/{$campaign->id}")->assertForbidden();
        $this->actingAs($stranger)->put("/campaigns/{$campaign->id}", ['name' => 'Mine', 'enemy_hp' => 'exact'])->assertForbidden();
        $this->actingAs($stranger)->delete("/campaigns/{$campaign->id}")->assertForbidden();
        $this->assertNotNull($campaign->fresh());
    }

    public function test_players_cant_change_the_campaign()
    {
        $campaign = Campaign::factory()->create();
        $player = User::factory()->create();
        $this->join($campaign, $player);

        $this->actingAs($player)->put("/campaigns/{$campaign->id}", ['name' => 'Mine', 'enemy_hp' => 'exact'])->assertForbidden();
        $this->actingAs($player)->post("/campaigns/{$campaign->id}/invite")->assertForbidden();
        $this->actingAs($player)->post("/campaigns/{$campaign->id}/party", ['creature_id' => $this->character($player)->id])->assertForbidden();
    }

    public function test_the_dm_can_update_the_campaign()
    {
        $campaign = Campaign::factory()->create();

        $this->actingAs($campaign->owner)
            ->put("/campaigns/{$campaign->id}", ['name' => 'Renamed', 'description' => null, 'enemy_hp' => 'hidden'])
            ->assertSessionHasNoErrors();

        $campaign->refresh();
        $this->assertSame('Renamed', $campaign->name);
        $this->assertSame('hidden', $campaign->enemy_hp);

        $this->actingAs($campaign->owner)
            ->put("/campaigns/{$campaign->id}", ['name' => 'Renamed', 'enemy_hp' => 'everything'])
            ->assertSessionHasErrors('enemy_hp');
    }

    public function test_deleting_a_campaign_keeps_its_characters_and_encounters()
    {
        $campaign = Campaign::factory()->create();
        $hero = $this->character($campaign->owner, $campaign);
        $encounter = Encounter::factory()->for($campaign->owner)->create(['campaign_id' => $campaign->id]);
        $this->join($campaign, User::factory()->create(), $hero);

        $this->actingAs($campaign->owner)->delete("/campaigns/{$campaign->id}")->assertRedirect(route('campaigns.index'));

        $this->assertNull($campaign->fresh());
        $this->assertNull($hero->fresh()->campaign_id);
        $this->assertNull($encounter->fresh()->campaign_id);
        $this->assertDatabaseCount('campaign_user', 0);
    }

    public function test_the_dm_adds_and_removes_their_own_player_characters()
    {
        $campaign = Campaign::factory()->create();
        $dm = $campaign->owner;
        $hero = $this->character($dm);

        $this->actingAs($dm)->post("/campaigns/{$campaign->id}/party", ['creature_id' => $hero->id])->assertSessionHasNoErrors();
        $this->assertSame($campaign->id, $hero->fresh()->campaign_id);

        $player = User::factory()->create();
        $this->join($campaign, $player, $hero);

        $this->actingAs($dm)->delete("/campaigns/{$campaign->id}/party/{$hero->id}");
        $this->assertNull($hero->fresh()->campaign_id);
        // Whoever was playing it is still in the campaign, just without a character.
        $this->assertNull($campaign->players()->sole()->pivot->character_id);
    }

    public function test_only_your_own_player_characters_can_join_the_party()
    {
        $campaign = Campaign::factory()->create();
        $dm = $campaign->owner;
        $monster = Creature::factory()->for($dm)->create();
        $someoneElses = $this->character(User::factory()->create());
        $srd = Creature::factory()->srd()->create(['kind' => 'player']);

        foreach ([$monster, $someoneElses, $srd] as $creature) {
            $this->actingAs($dm)->post("/campaigns/{$campaign->id}/party", ['creature_id' => $creature->id])
                ->assertSessionHasErrors('creature_id');
            $this->assertNull($creature->fresh()->campaign_id);
        }
    }

    public function test_moving_a_character_to_another_campaign_releases_its_old_claim()
    {
        $dm = User::factory()->create(['plan' => 'pro']);
        [$first, $second] = Campaign::factory()->count(2)->for($dm, 'owner')->create();
        $hero = $this->character($dm, $first);
        $this->join($first, User::factory()->create(), $hero);

        $this->actingAs($dm)->post("/campaigns/{$second->id}/party", ['creature_id' => $hero->id]);

        $this->assertSame($second->id, $hero->fresh()->campaign_id);
        $this->assertNull($first->players()->sole()->pivot->character_id);
    }

    public function test_removing_a_character_that_isnt_in_the_party_is_not_found()
    {
        $campaign = Campaign::factory()->create();
        $hero = $this->character($campaign->owner);

        $this->actingAs($campaign->owner)->delete("/campaigns/{$campaign->id}/party/{$hero->id}")->assertNotFound();
    }

    public function test_the_dm_adds_and_removes_their_own_encounters()
    {
        $campaign = Campaign::factory()->create();
        $encounter = Encounter::factory()->for($campaign->owner)->create();
        $someoneElses = Encounter::factory()->create();

        $this->actingAs($campaign->owner)->post("/campaigns/{$campaign->id}/encounters", ['encounter_id' => $encounter->id])->assertSessionHasNoErrors();
        $this->assertSame($campaign->id, $encounter->fresh()->campaign_id);

        $this->actingAs($campaign->owner)->post("/campaigns/{$campaign->id}/encounters", ['encounter_id' => $someoneElses->id])
            ->assertSessionHasErrors('encounter_id');
        $this->assertNull($someoneElses->fresh()->campaign_id);

        $this->actingAs($campaign->owner)->delete("/campaigns/{$campaign->id}/encounters/{$encounter->id}");
        $this->assertNull($encounter->fresh()->campaign_id);
    }

    public function test_the_invite_page_remembers_where_guests_were_going()
    {
        $campaign = Campaign::factory()->create(['name' => 'Crimson Keep']);

        $this->get("/join/{$campaign->invite_token}")
            ->assertInertia(fn (Assert $page) => $page->component('Campaigns/Join')->where('campaign.name', 'Crimson Keep'))
            ->assertSessionHas('url.intended', route('campaigns.join', $campaign->invite_token));

        $this->get('/join/not-a-real-token')->assertNotFound();
    }

    public function test_signing_up_from_an_invite_comes_back_to_it()
    {
        $campaign = Campaign::factory()->create();
        $this->get("/join/{$campaign->invite_token}");

        $this->post('/register', [
            'name' => 'New Player',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('campaigns.join', $campaign->invite_token));
    }

    public function test_joining_a_campaign()
    {
        $campaign = Campaign::factory()->create();
        $player = User::factory()->create();

        $this->actingAs($player)->post("/join/{$campaign->invite_token}")->assertRedirect(route('campaigns.show', $campaign));
        $this->assertTrue($campaign->hasPlayer($player));

        // Following the link again just opens the campaign.
        $this->actingAs($player)->get("/join/{$campaign->invite_token}")->assertRedirect(route('campaigns.show', $campaign));
        $this->actingAs($player)->post("/join/{$campaign->invite_token}");
        $this->assertSame(1, $campaign->players()->count());
    }

    public function test_the_dm_cant_join_their_own_campaign()
    {
        $campaign = Campaign::factory()->create();

        $this->actingAs($campaign->owner)->post("/join/{$campaign->invite_token}")->assertRedirect(route('campaigns.show', $campaign));
        $this->assertSame(0, $campaign->players()->count());
    }

    public function test_joining_stops_at_the_players_own_limit()
    {
        $player = User::factory()->create();
        $this->join(Campaign::factory()->create(), $player);
        $campaign = Campaign::factory()->create();

        $this->actingAs($player)->post("/join/{$campaign->invite_token}")->assertSessionHasErrors('limit');
        $this->assertFalse($campaign->hasPlayer($player));
    }

    public function test_joining_stops_when_the_campaign_is_full()
    {
        $campaign = Campaign::factory()->create();
        $this->join($campaign, User::factory()->create());
        $this->join($campaign, User::factory()->create());

        $this->actingAs(User::factory()->create())->post("/join/{$campaign->invite_token}")
            ->assertSessionHasErrors(['limit' => 'This campaign is full: it already has 2 players. Ask the DM to make room.']);
        $this->assertSame(2, $campaign->players()->count());
    }

    public function test_a_pro_dm_has_room_for_more_players()
    {
        $campaign = Campaign::factory()->for(User::factory()->create(['plan' => 'pro']), 'owner')->create();
        $this->join($campaign, User::factory()->create());
        $this->join($campaign, User::factory()->create());

        $this->actingAs(User::factory()->create())->post("/join/{$campaign->invite_token}")->assertSessionHasNoErrors();
        $this->assertSame(3, $campaign->players()->count());
    }

    public function test_resetting_the_invite_link_stops_the_old_one()
    {
        $campaign = Campaign::factory()->create();
        $old = $campaign->invite_token;

        $this->actingAs($campaign->owner)->post("/campaigns/{$campaign->id}/invite");

        $this->assertNotSame($old, $campaign->fresh()->invite_token);
        $this->actingAs(User::factory()->create())->get("/join/{$old}")->assertNotFound();
    }

    public function test_players_claim_a_party_character()
    {
        $campaign = Campaign::factory()->create();
        $hero = $this->character($campaign->owner, $campaign);
        $player = User::factory()->create();
        $this->join($campaign, $player);

        $this->actingAs($player)->put("/campaigns/{$campaign->id}/character", ['character_id' => $hero->id])->assertSessionHasNoErrors();
        $this->assertSame($hero->id, $campaign->players()->sole()->pivot->character_id);

        $this->actingAs($player)->put("/campaigns/{$campaign->id}/character", ['character_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($campaign->players()->sole()->pivot->character_id);
    }

    public function test_players_cant_claim_a_taken_character_or_one_outside_the_party()
    {
        $campaign = Campaign::factory()->create();
        $hero = $this->character($campaign->owner, $campaign);
        $this->join($campaign, User::factory()->create(['name' => 'Pat']), $hero);
        $player = User::factory()->create();
        $this->join($campaign, $player);
        $outsider = $this->character($campaign->owner);

        $this->actingAs($player)->put("/campaigns/{$campaign->id}/character", ['character_id' => $hero->id])
            ->assertSessionHasErrors(['character_id' => 'Pat is already playing that character.']);
        $this->actingAs($player)->put("/campaigns/{$campaign->id}/character", ['character_id' => $outsider->id])
            ->assertSessionHasErrors('character_id');
        $this->assertNull($campaign->players()->whereKey($player->id)->sole()->pivot->character_id);
    }

    public function test_the_dm_cant_claim_a_character()
    {
        $campaign = Campaign::factory()->create();
        $hero = $this->character($campaign->owner, $campaign);

        $this->actingAs($campaign->owner)->put("/campaigns/{$campaign->id}/character", ['character_id' => $hero->id])->assertForbidden();
    }

    public function test_players_can_leave_and_the_dm_can_remove_them()
    {
        $campaign = Campaign::factory()->create();
        [$leaver, $removed, $other] = User::factory()->count(3)->create();
        $this->join($campaign, $leaver);
        $this->join($campaign, $removed);
        $this->join($campaign, $other);

        $this->actingAs($leaver)->delete("/campaigns/{$campaign->id}/players/{$leaver->id}")->assertRedirect(route('campaigns.index'));
        $this->actingAs($campaign->owner)->delete("/campaigns/{$campaign->id}/players/{$removed->id}");
        // Players can't remove each other.
        $this->actingAs($other)->delete("/campaigns/{$campaign->id}/players/{$campaign->owner->id}")->assertForbidden();

        $this->assertEquals([$other->id], $campaign->players()->pluck('users.id')->all());
    }

    public function test_making_a_character_for_a_campaign()
    {
        $campaign = Campaign::factory()->create();

        $this->actingAs($campaign->owner)->get("/compendium/create?campaign={$campaign->id}")
            ->assertInertia(fn (Assert $page) => $page->where('campaign', ['id' => $campaign->id, 'name' => $campaign->name]));

        $this->actingAs($campaign->owner)->post('/compendium', [
            'kind' => 'monster', 'name' => 'Aria', 'summary' => '', 'rating' => '', 'hp' => 20, 'ac' => 14, 'speed' => '',
            'stats' => [], 'traits' => [], 'actions' => [], 'campaign_id' => $campaign->id,
        ])->assertRedirect(route('campaigns.show', $campaign));

        $aria = $campaign->party()->sole();
        $this->assertSame('Aria', $aria->name);
        // Party members are always player characters.
        $this->assertSame('player', $aria->kind);
    }

    public function test_cant_make_a_character_for_someone_elses_campaign()
    {
        $campaign = Campaign::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/compendium', [
            'kind' => 'player', 'name' => 'Aria', 'summary' => '', 'rating' => '', 'hp' => 20, 'ac' => 14, 'speed' => '',
            'stats' => [], 'traits' => [], 'actions' => [], 'campaign_id' => $campaign->id,
        ])->assertNotFound();

        $this->assertSame(0, Creature::count());
    }

    public function test_saving_an_encounter_into_a_campaign()
    {
        $campaign = Campaign::factory()->create();
        $payload = ['name' => 'Ambush', 'campaignId' => $campaign->id, 'round' => 0, 'activeIndex' => 0, 'combatants' => []];

        $this->actingAs($campaign->owner)->post('/encounters', $payload)->assertSessionHasNoErrors();
        $this->assertSame($campaign->id, $campaign->owner->encounters()->sole()->campaign_id);

        $this->actingAs(User::factory()->create())->post('/encounters', $payload)->assertSessionHasErrors();
        $this->assertSame(1, Encounter::count());
    }

    public function test_the_tracker_gets_the_dms_campaigns_with_their_party()
    {
        $campaign = Campaign::factory()->create(['name' => 'Crimson Keep']);
        $hero = $this->character($campaign->owner, $campaign);
        // Campaigns you only play in aren't offered.
        $this->join(Campaign::factory()->create(), $campaign->owner);

        $this->actingAs($campaign->owner)->get("/?new_in_campaign={$campaign->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaigns', [['id' => $campaign->id, 'name' => 'Crimson Keep', 'enemyHp' => 'bands', 'partyIds' => [$hero->id]]])
                ->where('newInCampaign', $campaign->id)
            );

        $this->actingAs(User::factory()->create())->get("/?new_in_campaign={$campaign->id}")
            ->assertInertia(fn (Assert $page) => $page->where('campaigns', [])->where('newInCampaign', null));
    }
}
