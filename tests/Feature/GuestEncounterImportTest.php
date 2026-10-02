<?php

namespace Tests\Feature;

use App\Models\Creature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestEncounterImportTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New DM',
            'email' => 'dm@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }

    private function guestEncounter(array $overrides = []): array
    {
        $boss = Creature::factory()->srd()->create(['name' => 'Goblin Boss']);
        $wolf = Creature::factory()->srd()->create(['name' => 'Wolf']);

        return array_merge([
            'name' => 'Goblin ambush',
            'round' => 3,
            'activeIndex' => 1,
            'combatants' => [
                ['id' => 'c1', 'creatureId' => $boss->id, 'name' => 'Goblin Boss', 'initiative' => 18, 'hp' => 9, 'maxHp' => 21, 'ac' => 17, 'conditions' => ['Poisoned']],
                ['id' => 'c2', 'creatureId' => $wolf->id, 'name' => 'Wolf', 'initiative' => 16, 'hp' => 11, 'maxHp' => 11, 'ac' => 13, 'conditions' => []],
            ],
        ], $overrides);
    }

    private function newUser(): User
    {
        return User::where('email', 'dm@example.com')->firstOrFail();
    }

    public function test_the_guest_fight_is_copied_into_the_new_account()
    {
        $this->post('/register', $this->registration(['guest_encounter' => $this->guestEncounter()]))
            ->assertRedirect(route('encounters.index', absolute: false));

        $encounter = $this->newUser()->encounters()->sole();

        $this->assertSame('Goblin ambush', $encounter->name);
        $this->assertSame(3, $encounter->round);
        $this->assertSame(1, $encounter->active_index);
        $this->assertSame(9, $encounter->combatants[0]['hp']);
        $this->assertSame(['Poisoned'], $encounter->combatants[0]['conditions']);
    }

    public function test_a_guests_quick_added_players_come_across_too()
    {
        $guest = $this->guestEncounter();
        $guest['combatants'][] = [
            'id' => 'q1', 'creatureId' => null, 'name' => 'Kestrel', 'side' => 'player',
            'initiative' => 12, 'hp' => 30, 'maxHp' => 33, 'ac' => 13, 'conditions' => [],
        ];

        $this->post('/register', $this->registration(['guest_encounter' => $guest]));

        $combatants = $this->newUser()->encounters()->sole()->combatants;
        $this->assertSame('Kestrel', $combatants[2]['name']);
        $this->assertNull($combatants[2]['creatureId']);
        $this->assertSame(30, $combatants[2]['hp']);
    }

    public function test_registration_without_a_guest_fight_creates_no_encounter()
    {
        $this->post('/register', $this->registration());

        $this->assertAuthenticated();
        $this->assertSame(0, $this->newUser()->encounters()->count());
    }

    public function test_invalid_guest_data_is_skipped_without_blocking_registration()
    {
        $invalid = $this->guestEncounter(['activeIndex' => 5, 'combatants' => [['name' => str_repeat('x', 500)]]]);

        $this->post('/register', $this->registration(['guest_encounter' => $invalid]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('encounters.index', absolute: false));

        $this->assertAuthenticated();
        $this->assertSame(0, $this->newUser()->encounters()->count());
    }

    public function test_a_guest_fight_using_someone_elses_homebrew_is_skipped()
    {
        $guest = $this->guestEncounter();
        $guest['combatants'][0]['creatureId'] = Creature::factory()->create()->id; // owned by another user

        $this->post('/register', $this->registration(['guest_encounter' => $guest]));

        $this->assertAuthenticated();
        $this->assertSame(0, $this->newUser()->encounters()->count());
    }

    public function test_only_validated_fields_are_stored_and_hp_is_capped_at_max()
    {
        $guest = $this->guestEncounter();
        $guest['combatants'][0]['hp'] = 999;
        $guest['combatants'][0]['isAdmin'] = true;

        $this->post('/register', $this->registration(['guest_encounter' => $guest]));

        $combatant = $this->newUser()->encounters()->sole()->combatants[0];

        $this->assertSame(21, $combatant['hp']);
        $this->assertArrayNotHasKey('isAdmin', $combatant);
    }
}
