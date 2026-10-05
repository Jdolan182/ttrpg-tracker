<?php

namespace Tests\Feature;

use App\Events\CampaignCombatChanged;
use App\Models\Campaign;
use App\Models\Creature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlayerViewTest extends TestCase
{
    use RefreshDatabase;

    private function combatant(string $name, array $overrides = []): array
    {
        return [
            'id' => strtolower($name), 'creatureId' => null, 'name' => $name, 'side' => 'enemy', 'initiative' => 10,
            'hp' => 20, 'maxHp' => 20, 'ac' => 12, 'conditions' => [], 'used' => [], ...$overrides,
        ];
    }

    private function fight(array $combatants, int $round = 1, int $activeIndex = 0): array
    {
        return ['name' => 'Ambush', 'round' => $round, 'activeIndex' => $activeIndex, 'combatants' => $combatants];
    }

    /** A campaign with one player in it. */
    private function campaignWithPlayer(string $enemyHp = 'bands'): array
    {
        $campaign = Campaign::factory()->create(['enemy_hp' => $enemyHp]);
        $player = User::factory()->create();
        $campaign->players()->attach($player);

        return [$campaign, $player];
    }

    private function push(Campaign $campaign, array $fight)
    {
        return $this->actingAs($campaign->owner)->putJson("/campaigns/{$campaign->id}/combat", $fight);
    }

    private function viewAs(User $user, Campaign $campaign)
    {
        return $this->actingAs($user)->getJson("/campaigns/{$campaign->id}/combat");
    }

    public function test_players_see_the_fight_without_hidden_combatants()
    {
        [$campaign, $player] = $this->campaignWithPlayer();

        $this->push($campaign, $this->fight([
            $this->combatant('Aria', ['side' => 'player', 'hp' => 9, 'maxHp' => 30, 'tempHp' => 5, 'conditions' => ['Poisoned'], 'durations' => ['Poisoned' => 2]]),
            $this->combatant('Lurker', ['hidden' => true]),
            $this->combatant('Goblin', ['hp' => 4, 'maxHp' => 10, 'concentrating' => true]),
        ], activeIndex: 2))->assertNoContent();

        $combat = $this->viewAs($player, $campaign)->assertOk()->json('combat');

        $this->assertSame('Ambush', $combat['name']);
        $this->assertSame(1, $combat['round']);
        $this->assertSame(['Aria', 'Goblin'], array_column($combat['combatants'], 'name'));

        [$aria, $goblin] = $combat['combatants'];
        $this->assertEquals(
            ['hp' => 9, 'maxHp' => 30, 'tempHp' => 5, 'status' => 'bloodied', 'active' => false, 'conditions' => [['name' => 'Poisoned', 'rounds' => 2]]],
            array_intersect_key($aria, array_flip(['hp', 'maxHp', 'tempHp', 'status', 'active', 'conditions'])),
        );
        // Enemies in bands: how they're doing, never the numbers.
        $this->assertNull($goblin['hp']);
        $this->assertNull($goblin['maxHp']);
        $this->assertSame('bloodied', $goblin['status']);
        $this->assertTrue($goblin['active']);
        $this->assertTrue($goblin['concentrating']);
    }

    public function test_the_hidden_combatants_turn_shows_nobody_as_active()
    {
        [$campaign, $player] = $this->campaignWithPlayer();

        $this->push($campaign, $this->fight([$this->combatant('Goblin'), $this->combatant('Lurker', ['hidden' => true])], activeIndex: 1));

        $combat = $this->viewAs($player, $campaign)->json('combat');
        $this->assertSame([false], array_column($combat['combatants'], 'active'));
        $this->assertStringNotContainsString('Lurker', json_encode($combat));
    }

    public function test_exact_enemy_hp()
    {
        [$campaign, $player] = $this->campaignWithPlayer('exact');

        $this->push($campaign, $this->fight([$this->combatant('Goblin', ['hp' => 15])]));

        $goblin = $this->viewAs($player, $campaign)->json('combat.combatants.0');
        $this->assertSame([15, 20, 'healthy'], [$goblin['hp'], $goblin['maxHp'], $goblin['status']]);
    }

    public function test_hidden_enemy_hp_only_shows_when_they_drop()
    {
        [$campaign, $player] = $this->campaignWithPlayer('hidden');

        $this->push($campaign, $this->fight([
            $this->combatant('Goblin', ['hp' => 3]),
            $this->combatant('Orc', ['hp' => 0]),
            // Allies are on the players' side, so they're always shown.
            $this->combatant('Guard', ['side' => 'ally', 'hp' => 3]),
        ]));

        [$goblin, $orc, $guard] = $this->viewAs($player, $campaign)->json('combat.combatants');
        $this->assertSame([null, null], [$goblin['hp'], $goblin['status']]);
        $this->assertSame([null, 'down'], [$orc['hp'], $orc['status']]);
        $this->assertSame([3, 'bloodied'], [$guard['hp'], $guard['status']]);
    }

    public function test_player_characters_at_zero_hp_follow_their_death_saves()
    {
        [$campaign, $player] = $this->campaignWithPlayer();

        $this->push($campaign, $this->fight([
            $this->combatant('Aria', ['side' => 'player', 'hp' => 0]),
            $this->combatant('Borin', ['side' => 'player', 'hp' => 0, 'deathSaves' => ['successes' => 3, 'failures' => 1]]),
            $this->combatant('Cid', ['side' => 'player', 'hp' => 0, 'deathSaves' => ['successes' => 1, 'failures' => 3]]),
        ]));

        $this->assertSame(['dying', 'stable', 'dead'], array_column($this->viewAs($player, $campaign)->json('combat.combatants'), 'status'));
    }

    public function test_setup_is_never_shown()
    {
        [$campaign, $player] = $this->campaignWithPlayer();

        $this->push($campaign, $this->fight([$this->combatant('Goblin')]));
        $this->push($campaign, $this->fight([$this->combatant('Goblin')], round: 0))->assertNoContent();

        $this->viewAs($player, $campaign)->assertExactJson(['combat' => null]);
        $this->assertNull($campaign->fresh()->live);
    }

    public function test_ending_combat_stops_showing_it()
    {
        [$campaign, $player] = $this->campaignWithPlayer();
        $this->push($campaign, $this->fight([$this->combatant('Goblin')]));

        $this->actingAs($campaign->owner)->deleteJson("/campaigns/{$campaign->id}/combat")->assertNoContent();

        $this->viewAs($player, $campaign)->assertExactJson(['combat' => null]);
    }

    public function test_only_the_dm_can_send_or_end_the_fight()
    {
        [$campaign, $player] = $this->campaignWithPlayer();
        $stranger = User::factory()->create();

        foreach ([$player, $stranger] as $user) {
            $this->actingAs($user)->putJson("/campaigns/{$campaign->id}/combat", $this->fight([$this->combatant('Goblin')]))->assertForbidden();
            $this->actingAs($user)->deleteJson("/campaigns/{$campaign->id}/combat")->assertForbidden();
        }
        $this->assertNull($campaign->fresh()->live);
    }

    public function test_strangers_and_guests_cant_watch()
    {
        [$campaign] = $this->campaignWithPlayer();

        $this->getJson("/campaigns/{$campaign->id}/combat")->assertUnauthorized();
        $this->viewAs(User::factory()->create(), $campaign)->assertForbidden();
    }

    public function test_the_fight_is_validated_like_a_save()
    {
        [$campaign] = $this->campaignWithPlayer();
        $someoneElses = Creature::factory()->create();

        $this->push($campaign, $this->fight([$this->combatant('Goblin', ['creatureId' => $someoneElses->id])]))
            ->assertUnprocessable()->assertJsonValidationErrors('combatants');
        $this->push($campaign, $this->fight([$this->combatant('Goblin')], activeIndex: 3))
            ->assertUnprocessable()->assertJsonValidationErrors('activeIndex');
        $this->push($campaign, ['name' => 'Ambush'])->assertUnprocessable();

        $this->assertNull($campaign->fresh()->live);
    }

    public function test_viewers_are_pinged_only_when_what_they_see_changes()
    {
        Event::fake([CampaignCombatChanged::class]);
        [$campaign] = $this->campaignWithPlayer();
        $goblin = $this->combatant('Goblin');
        $lurker = $this->combatant('Lurker', ['hidden' => true]);

        $this->push($campaign, $this->fight([$goblin, $lurker]));
        Event::assertDispatchedTimes(CampaignCombatChanged::class, 1);

        // Hurting someone players can't see, or changing AC, which they never see: no ping.
        $this->push($campaign, $this->fight([[...$goblin, 'ac' => 15], [...$lurker, 'hp' => 5]]));
        Event::assertDispatchedTimes(CampaignCombatChanged::class, 1);

        $this->push($campaign, $this->fight([[...$goblin, 'hp' => 5], $lurker]));
        Event::assertDispatchedTimes(CampaignCombatChanged::class, 2);

        Event::assertDispatched(CampaignCombatChanged::class, fn ($event) => $event->broadcastOn()[0]->name === "private-campaign.{$campaign->id}"
            && $event->broadcastWith() === []);
    }

    public function test_the_campaign_page_and_full_screen_view_get_the_fight()
    {
        [$campaign, $player] = $this->campaignWithPlayer();
        $this->push($campaign, $this->fight([$this->combatant('Goblin')]));

        $this->actingAs($player)->get("/campaigns/{$campaign->id}")
            ->assertInertia(fn (Assert $page) => $page->where('combat.combatants.0.name', 'Goblin'));

        $this->actingAs($player)->get("/campaigns/{$campaign->id}/combat")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Campaigns/Combat')
                ->where('campaign.isDm', false)
                ->where('combat.round', 1)
            );
    }

    public function test_the_unfiltered_fight_never_reaches_the_frontend()
    {
        [$campaign, $player] = $this->campaignWithPlayer();
        $this->push($campaign, $this->fight([$this->combatant('Lurker', ['hidden' => true])]));

        $this->actingAs($player)->get("/campaigns/{$campaign->id}")->assertDontSee('Lurker');
        $this->assertArrayNotHasKey('live', $campaign->fresh()->toArray());
    }

    private function entry(string $type, int $round, array $fields = []): array
    {
        static $n = 0;

        return ['id' => 'e'.++$n, 'at' => now()->toIso8601String(), 'round' => $round, 'type' => $type, ...$fields];
    }

    public function test_players_see_the_history_without_what_the_dm_kept_from_them()
    {
        [$campaign, $player] = $this->campaignWithPlayer();
        $lurker = $this->combatant('Lurker', ['hidden' => true]);
        $scout = $this->combatant('Scout');

        $this->push($campaign, [...$this->fight([$this->combatant('Aria', ['side' => 'player']), $this->combatant('Goblin'), $lurker, $scout]), 'log' => [
            // Setup, and the scout (visible now) was hidden then.
            $this->entry('joined', 0, ['targets' => ['Goblin'], 'amount' => 12]),
            $this->entry('hidden', 0, ['targets' => ['Scout']]),
            $this->entry('combat_started', 1),
            $this->entry('turn', 1, ['actor' => 'Scout']),
            $this->entry('damage', 1, ['targets' => ['Aria'], 'amount' => 4]),
            $this->entry('revealed', 1, ['targets' => ['Scout']]),
            $this->entry('turn', 1, ['actor' => 'Goblin']),
            $this->entry('heal', 1, ['targets' => ['Goblin'], 'amount' => 5]),
            $this->entry('heal', 1, ['targets' => ['Aria'], 'amount' => 3]),
            // The lurker was visible, then hidden: what happened before still shows.
            $this->entry('damage', 1, ['targets' => ['Lurker'], 'amount' => 2]),
            $this->entry('hidden', 1, ['targets' => ['Lurker']]),
            $this->entry('turn', 1, ['actor' => 'Lurker']),
            $this->entry('action', 1, ['actor' => 'Lurker', 'detail' => 'Dagger', 'targets' => ['Aria'], 'amount' => 6, 'effect' => 'damage']),
            $this->entry('damage', 1, ['targets' => ['Lurker', 'Goblin'], 'amount' => 8]),
        ]]);

        $log = $this->viewAs($player, $campaign)->json('combat.log');

        $this->assertSame(
            [
                ['combat_started', null, null, null],
                // The scout's turn while hidden: it happened, but not whose it was.
                ['turn', null, null, null],
                ['damage', null, ['Aria'], 4],
                ['turn', 'Goblin', null, null],
                // How much an enemy healed is kept from players in bands mode.
                ['heal', null, ['Goblin'], null],
                ['heal', null, ['Aria'], 3],
                ['damage', null, ['Lurker'], 2],
                ['turn', null, null, null],
                // Hit both: only the one players can see is named.
                ['damage', null, ['Goblin'], 8],
            ],
            array_map(fn ($e) => [$e['type'], $e['actor'] ?? null, $e['targets'] ?? null, $e['amount'] ?? null], $log),
        );
    }

    public function test_enemy_healing_is_shown_with_exact_hp()
    {
        [$campaign, $player] = $this->campaignWithPlayer('exact');

        $this->push($campaign, [...$this->fight([$this->combatant('Goblin')]), 'log' => [$this->entry('heal', 1, ['targets' => ['Goblin'], 'amount' => 5])]]);

        $this->assertSame(5, $this->viewAs($player, $campaign)->json('combat.log.0.amount'));
    }

    public function test_the_tracker_only_sends_the_latest_history()
    {
        [$campaign] = $this->campaignWithPlayer();
        $log = array_map(fn () => $this->entry('sorted', 1), range(1, 151));

        $this->push($campaign, [...$this->fight([$this->combatant('Goblin')]), 'log' => $log])->assertUnprocessable()->assertJsonValidationErrors('log');
    }

    public function test_the_same_computer_player_view_is_open_to_guests()
    {
        $this->get('/player-view')->assertOk()->assertInertia(fn (Assert $page) => $page->component('PlayerView'));
    }

    public function test_encounters_can_be_saved_with_combat_ended_in_the_history()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/encounters', [
            'name' => 'Done', 'round' => 0, 'activeIndex' => 0, 'combatants' => [],
            'log' => [['id' => 'a', 'at' => now()->toIso8601String(), 'round' => 3, 'type' => 'combat_ended']],
        ])->assertSessionHasNoErrors();
    }
}
