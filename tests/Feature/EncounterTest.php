<?php

namespace Tests\Feature;

use App\Models\Creature;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EncounterTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Creature $creature, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Goblin ambush',
            'round' => 2,
            'activeIndex' => 1,
            'combatants' => [
                ['id' => 'a', 'creatureId' => $creature->id, 'name' => "{$creature->name} 1", 'side' => 'enemy', 'initiative' => 15, 'hp' => 3, 'maxHp' => 7, 'ac' => 15, 'conditions' => ['Prone']],
                ['id' => 'b', 'creatureId' => $creature->id, 'name' => "{$creature->name} 2", 'side' => 'ally', 'initiative' => 9, 'hp' => 7, 'maxHp' => 7, 'ac' => 15, 'conditions' => []],
            ],
        ], $overrides);
    }

    public function test_the_tracker_lists_srd_creatures_for_guests_and_own_creatures_for_users()
    {
        $srd = Creature::factory()->srd()->create(['name' => 'Goblin']);
        $user = User::factory()->create();
        Creature::factory()->for($user)->create(['name' => 'My Boss']);
        Creature::factory()->create(['name' => 'Someone Else']);

        $this->get('/')->assertInertia(fn ($page) => $page
            ->has('creatures', 1)
            ->where('creatures.0.id', $srd->id)
            ->has('savedEncounters', 0));

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page
            ->has('creatures', 2)
            ->where('creatures.0.name', 'Goblin')
            ->where('creatures.1.name', 'My Boss'));
    }

    public function test_the_tracker_only_lists_the_signed_in_users_encounters()
    {
        $user = User::factory()->create();
        Encounter::factory()->for($user)->create(['name' => 'Mine']);
        Encounter::factory()->create(['name' => 'Not mine']);

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page
            ->has('savedEncounters', 1)
            ->where('savedEncounters.0.name', 'Mine'));
    }

    public function test_a_user_can_save_an_encounter_mid_fight()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create(['name' => 'Goblin']);

        $this->actingAs($user)->post('/encounters', $this->payload($goblin))
            ->assertRedirect('/')
            ->assertSessionHas('savedEncounterId');

        $encounter = $user->encounters()->sole();
        $this->assertSame('Goblin ambush', $encounter->name);
        $this->assertSame(2, $encounter->round);
        $this->assertSame(1, $encounter->active_index);
        $this->assertSame(3, $encounter->combatants[0]['hp']);
        $this->assertSame(['Prone'], $encounter->combatants[0]['conditions']);
    }

    public function test_an_empty_encounter_can_be_saved()
    {
        $this->actingAs(User::factory()->create())
            ->post('/encounters', ['name' => 'Prep', 'round' => 1, 'activeIndex' => 0, 'combatants' => []])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('encounters', ['name' => 'Prep']);
    }

    public function test_quick_added_combatants_without_a_creature_can_be_saved()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create();

        $payload = $this->payload($goblin);
        $payload['combatants'][] = [
            'id' => 'q1', 'creatureId' => null, 'name' => 'Aria', 'side' => 'player',
            'initiative' => 18, 'hp' => 38, 'maxHp' => 38, 'ac' => 15, 'conditions' => [],
        ];
        // Any side is fine without a creature to check against.
        $payload['combatants'][] = [
            'id' => 'q2', 'creatureId' => null, 'name' => 'Town guard', 'side' => 'ally',
            'initiative' => 5, 'hp' => 11, 'maxHp' => 11, 'ac' => 16, 'conditions' => [],
        ];

        $payload['combatants'][2]['stats'] = [['label' => 'STR', 'value' => 12], ['label' => 'DEX', 'value' => '18']];
        // Combatants from the compendium take stats from their creature, so any sent are dropped.
        $payload['combatants'][0]['stats'] = [['label' => 'STR', 'value' => 99]];

        $this->actingAs($user)->post('/encounters', $payload)->assertSessionHasNoErrors();

        $saved = $user->encounters()->sole()->combatants;
        $this->assertNull($saved[2]['creatureId']);
        $this->assertSame(['player', 'ally'], [$saved[2]['side'], $saved[3]['side']]);
        $this->assertEquals([['label' => 'STR', 'value' => 12], ['label' => 'DEX', 'value' => 18]], $saved[2]['stats']);
        $this->assertArrayNotHasKey('stats', $saved[0]);
        $this->assertArrayNotHasKey('stats', $saved[3]);
    }

    public function test_quick_added_stats_are_validated()
    {
        $goblin = Creature::factory()->srd()->create();
        $payload = $this->payload($goblin);
        $payload['combatants'][] = [
            'id' => 'q1', 'creatureId' => null, 'name' => 'Aria', 'side' => 'player', 'initiative' => 1,
            'hp' => 1, 'maxHp' => 1, 'ac' => 1, 'conditions' => [], 'stats' => [['label' => str_repeat('X', 30), 'value' => 'lots']],
        ];

        $this->actingAs(User::factory()->create())->post('/encounters', $payload)
            ->assertSessionHasErrors(['combatants.2.stats.0.label', 'combatants.2.stats.0.value']);
    }

    public function test_fight_state_is_saved_and_tidied()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create();
        $payload = $this->payload($goblin);

        // Goblin 1 is Prone (timed) and concentrating, with temp HP, hidden from players.
        $payload['combatants'][0] += [
            'durations' => ['Prone' => 2, 'Stunned' => 3], // Stunned isn't one of its conditions
            'tempHp' => 5,
            'concentrating' => true,
            'hidden' => true,
            'deathSaves' => ['successes' => 1, 'failures' => 2], // not a player, so dropped
        ];
        $payload['combatants'][] = [
            'id' => 'q1', 'creatureId' => null, 'name' => 'Aria', 'side' => 'player', 'initiative' => 3,
            'hp' => 0, 'maxHp' => 38, 'ac' => 15, 'conditions' => [], 'deathSaves' => ['successes' => 1, 'failures' => 2],
        ];
        $payload['log'] = collect(['condition_expired', 'temp_hp', 'concentration', 'death_save', 'stabilized', 'died', 'hidden', 'revealed'])
            ->map(fn (string $type, int $i) => ['id' => "l{$i}", 'at' => '2026-10-02T12:00:00Z', 'round' => 2, 'type' => $type, 'targets' => ['Goblin 1']])
            ->all();

        $this->actingAs($user)->post('/encounters', $payload)->assertSessionHasNoErrors();

        [$goblinOne, $goblinTwo, $aria] = $user->encounters()->sole()->combatants;
        $this->assertSame(['Prone' => 2], $goblinOne['durations']);
        $this->assertSame(5, $goblinOne['tempHp']);
        $this->assertTrue($goblinOne['concentrating']);
        $this->assertTrue($goblinOne['hidden']);
        $this->assertArrayNotHasKey('deathSaves', $goblinOne);
        // Nothing optional is stored for a plain combatant.
        $this->assertEqualsCanonicalizing(['id', 'creatureId', 'name', 'side', 'initiative', 'hp', 'maxHp', 'ac', 'conditions', 'used'], array_keys($goblinTwo));
        $this->assertEquals(['successes' => 1, 'failures' => 2], $aria['deathSaves']);
    }

    public function test_fight_state_is_validated()
    {
        $goblin = Creature::factory()->srd()->create();
        $payload = $this->payload($goblin);
        $payload['combatants'][0] += [
            'durations' => ['Prone' => 0],
            'tempHp' => -3,
            'concentrating' => 'maybe',
            'deathSaves' => ['successes' => 4, 'failures' => 0],
        ];

        $this->actingAs(User::factory()->create())->post('/encounters', $payload)->assertSessionHasErrors([
            'combatants.0.durations.Prone',
            'combatants.0.tempHp',
            'combatants.0.concentrating',
            'combatants.0.deathSaves.successes',
        ]);
    }

    public function test_the_history_and_action_uses_are_saved()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create();

        $payload = $this->payload($goblin);
        $payload['combatants'][0]['used'] = ['Scimitar' => 2, 'Unused' => 0];
        $payload['log'] = [
            ['id' => 'l1', 'at' => '2026-09-28T16:00:00Z', 'round' => 1, 'type' => 'turn', 'actor' => 'Goblin 1'],
            [
                'id' => 'l2', 'at' => '2026-09-28T16:00:05Z', 'round' => 1, 'type' => 'action', 'actor' => 'Goblin 1',
                'detail' => 'Scimitar', 'targets' => ['Goblin 2'], 'amount' => 5, 'effect' => 'damage', 'sneaky' => 'dropped',
            ],
        ];

        $this->actingAs($user)->post('/encounters', $payload)->assertSessionHasNoErrors();

        $encounter = $user->encounters()->sole();
        $this->assertSame(['Scimitar' => 2], $encounter->combatants[0]['used']);
        $this->assertSame([], $encounter->combatants[1]['used']);
        $this->assertCount(2, $encounter->log);
        // Unknown keys are dropped (jsonb keeps its own key order, hence canonicalizing).
        $this->assertEqualsCanonicalizing(['id', 'at', 'round', 'type', 'actor', 'targets', 'amount', 'effect', 'detail'], array_keys($encounter->log[1]));
        $this->assertSame('Scimitar', $encounter->log[1]['detail']);

        $this->get('/')->assertInertia(fn ($page) => $page->has('openEncounter.log', 2));
    }

    public function test_history_entries_are_validated()
    {
        $goblin = Creature::factory()->srd()->create();
        $this->actingAs(User::factory()->create());

        $badType = $this->payload($goblin, ['log' => [['id' => 'x', 'at' => '2026-09-28T16:00:00Z', 'round' => 1, 'type' => 'fireworks']]]);
        $this->post('/encounters', $badType)->assertSessionHasErrors('log.0.type');

        $tooMany = $this->payload($goblin, ['log' => array_fill(0, 1001, ['id' => 'x', 'at' => '2026-09-28T16:00:00Z', 'round' => 1, 'type' => 'turn'])]);
        $this->post('/encounters', $tooMany)->assertSessionHasErrors('log');

        $this->assertDatabaseCount('encounters', 0);
    }

    public function test_encounters_saved_before_the_history_existed_have_an_empty_one()
    {
        $user = User::factory()->create();
        Encounter::factory()->for($user)->create();

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page->where('openEncounter.log', []));
    }

    public function test_the_list_only_has_names_and_one_encounter_is_sent_in_full()
    {
        $user = User::factory()->create();
        $older = Encounter::factory()->for($user)->create(['name' => 'Older', 'updated_at' => now()->subDay()]);
        $newer = Encounter::factory()->for($user)->create(['name' => 'Newer']);

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page
            ->where('savedEncounters', [['id' => $newer->id, 'name' => 'Newer'], ['id' => $older->id, 'name' => 'Older']])
            // Without ?encounter=, the most recently updated one is sent in full.
            ->where('openEncounter.id', $newer->id)
            ->has('openEncounter.combatants')
            ->has('openEncounter.log'));

        $this->get("/?encounter={$older->id}")->assertInertia(fn ($page) => $page->where('openEncounter.id', $older->id));
    }

    public function test_another_users_encounter_cannot_be_opened()
    {
        $user = User::factory()->create();
        $theirs = Encounter::factory()->create();

        $this->actingAs($user)->get("/?encounter={$theirs->id}")->assertInertia(fn ($page) => $page->where('openEncounter', null));

        auth()->logout();
        $this->get("/?encounter={$theirs->id}")->assertInertia(fn ($page) => $page->where('openEncounter', null));
    }

    public function test_an_encounter_can_be_saved_before_combat_starts()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create();

        $this->actingAs($user)
            ->post('/encounters', $this->payload($goblin, ['round' => 0, 'activeIndex' => 0]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $user->encounters()->sole()->round);

        $this->post('/encounters', $this->payload($goblin, ['round' => -1]))->assertSessionHasErrors('round');
    }

    public function test_a_combatant_can_be_saved_without_an_initiative_yet()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create();
        $payload = $this->payload($goblin, ['round' => 0, 'activeIndex' => 0]);
        $payload['combatants'][0]['initiative'] = null;
        // A real roll of 0 is kept as 0.
        $payload['combatants'][1]['initiative'] = 0;

        $this->actingAs($user)->post('/encounters', $payload)->assertSessionHasNoErrors();

        $this->assertSame([null, 0], array_column($user->encounters()->sole()->combatants, 'initiative'));

        unset($payload['combatants'][0]['initiative']);
        $this->post('/encounters', $payload)->assertSessionHasErrors('combatants.0.initiative');
    }

    public function test_guests_cannot_save_encounters()
    {
        $goblin = Creature::factory()->srd()->create();

        $this->post('/encounters', $this->payload($goblin))->assertRedirect('/login');
        $this->assertDatabaseCount('encounters', 0);
    }

    public function test_an_encounter_cannot_use_another_users_creature()
    {
        $user = User::factory()->create();
        $theirs = Creature::factory()->create();

        $this->actingAs($user)->post('/encounters', $this->payload($theirs))->assertSessionHasErrors('combatants');
        $this->assertDatabaseCount('encounters', 0);
    }

    public function test_sides_are_saved_and_can_differ_for_the_same_creature()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create();

        $this->actingAs($user)->post('/encounters', $this->payload($goblin))->assertSessionHasNoErrors();

        $combatants = $user->encounters()->sole()->combatants;
        $this->assertSame(['enemy', 'ally'], array_column($combatants, 'side'));
    }

    public function test_only_player_characters_can_be_on_the_player_side()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create();
        $hero = Creature::factory()->for($user)->create(['kind' => 'player']);
        $this->actingAs($user);

        $monsterAsPlayer = $this->payload($goblin);
        $monsterAsPlayer['combatants'][0]['side'] = 'player';
        $this->post('/encounters', $monsterAsPlayer)->assertSessionHasErrors('combatants.0.side');

        $playerAsAlly = $this->payload($hero);
        $playerAsAlly['combatants'][0]['side'] = 'player';
        $this->post('/encounters', $playerAsAlly)->assertSessionHasErrors('combatants.1.side');

        $this->post('/encounters', $this->payload($goblin, ['combatants' => [
            ['id' => 'x', 'creatureId' => $goblin->id, 'name' => 'Goblin', 'side' => 'sidekick', 'initiative' => 1, 'hp' => 1, 'maxHp' => 1, 'ac' => 1, 'conditions' => []],
        ]]))->assertSessionHasErrors('combatants.0.side');

        $this->assertDatabaseCount('encounters', 0);
    }

    public function test_a_missing_side_defaults_from_the_creature()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create(['kind' => 'monster']);
        $captain = Creature::factory()->srd()->create(['kind' => 'npc']);
        $hero = Creature::factory()->for($user)->create(['kind' => 'player']);

        $combatant = fn (Creature $creature, string $id) => [
            'id' => $id, 'creatureId' => $creature->id, 'name' => $creature->name, 'initiative' => 1, 'hp' => 1, 'maxHp' => 1, 'ac' => 1, 'conditions' => [],
        ];

        $this->actingAs($user)->post('/encounters', $this->payload($goblin, ['activeIndex' => 0, 'combatants' => [
            $combatant($goblin, 'a'), $combatant($captain, 'b'), $combatant($hero, 'c'),
        ]]))->assertSessionHasNoErrors();

        $this->assertSame(['enemy', 'neutral', 'player'], array_column($user->encounters()->sole()->combatants, 'side'));
    }

    public function test_the_active_turn_must_be_a_combatant()
    {
        $goblin = Creature::factory()->srd()->create();

        $this->actingAs(User::factory()->create())
            ->post('/encounters', $this->payload($goblin, ['activeIndex' => 2]))
            ->assertSessionHasErrors('activeIndex');
    }

    public function test_a_user_can_update_and_delete_their_encounter()
    {
        $user = User::factory()->create();
        $goblin = Creature::factory()->srd()->create();
        $encounter = Encounter::factory()->for($user)->create();

        $this->actingAs($user)->put("/encounters/{$encounter->id}", $this->payload($goblin, ['name' => 'Renamed']))
            ->assertSessionHas('savedEncounterId', $encounter->id);
        $this->assertSame('Renamed', $encounter->fresh()->name);

        $this->actingAs($user)->delete("/encounters/{$encounter->id}")->assertRedirect('/');
        $this->assertModelMissing($encounter);
    }

    public function test_a_user_cannot_touch_someone_elses_encounter()
    {
        $goblin = Creature::factory()->srd()->create();
        $encounter = Encounter::factory()->create(['name' => 'Theirs']);

        $this->actingAs(User::factory()->create());
        $this->put("/encounters/{$encounter->id}", $this->payload($goblin))->assertForbidden();
        $this->delete("/encounters/{$encounter->id}")->assertForbidden();

        $this->assertSame('Theirs', $encounter->fresh()->name);
    }
}
