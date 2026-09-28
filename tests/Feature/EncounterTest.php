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
