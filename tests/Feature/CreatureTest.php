<?php

namespace Tests\Feature;

use App\Models\Creature;
use App\Models\User;
use Database\Seeders\SrdCreatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatureTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'monster',
            'name' => 'Goblin Shaman',
            'summary' => 'Small humanoid (goblinoid), neutral evil',
            'rating' => 'CR 1',
            'hp' => '18',
            'ac' => '13',
            'speed' => '30 ft.',
            'stats' => [['label' => 'Might', 'value' => '8'], ['label' => 'Wits', 'value' => '16']],
            'traits' => [['name' => 'Nimble Escape', 'description' => 'Disengage or Hide as a bonus action.']],
            'actions' => [['name' => 'Hex Bolt', 'description' => 'Ranged spell attack: +5 to hit. Hit: 7 necrotic damage.', 'extra' => 'dropped']],
        ], $overrides);
    }

    public function test_the_srd_seeder_is_safe_to_run_twice()
    {
        $this->seed(SrdCreatureSeeder::class);
        $goblinId = Creature::whereNull('user_id')->where('name', 'Goblin')->value('id');
        $count = Creature::count();

        $this->seed(SrdCreatureSeeder::class);

        $this->assertSame($count, Creature::count());
        $this->assertSame($goblinId, Creature::where('name', 'Goblin')->value('id'));
        $this->assertSame(['label' => 'STR', 'value' => 8], Creature::find($goblinId)->stats[0]);
    }

    public function test_a_user_can_create_a_creature_with_their_own_stats()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/compendium', $this->payload())->assertSessionHasNoErrors();

        $creature = $user->creatures()->sole();
        $this->assertSame('Goblin Shaman', $creature->name);
        $this->assertSame(18, $creature->hp);
        // Order and integer values survive the round trip through jsonb.
        $this->assertSame([['label' => 'Might', 'value' => 8], ['label' => 'Wits', 'value' => 16]], $creature->stats);
        $this->assertSame([['name' => 'Hex Bolt', 'description' => 'Ranged spell attack: +5 to hit. Hit: 7 necrotic damage.']], $creature->actions);
    }

    public function test_creating_a_creature_redirects_to_it_in_the_compendium()
    {
        $this->actingAs(User::factory()->create())->post('/compendium', $this->payload())
            ->assertRedirect(route('compendium.index', ['creature' => Creature::sole()->id]));
    }

    public function test_guests_cannot_create_creatures()
    {
        $this->get('/compendium/create')->assertRedirect('/login');
        $this->post('/compendium', $this->payload())->assertRedirect('/login');
        $this->assertDatabaseCount('creatures', 0);
    }

    public function test_creature_validation()
    {
        $this->actingAs(User::factory()->create())
            ->post('/compendium', $this->payload([
                'kind' => 'dragon-god',
                'name' => '',
                'hp' => 0,
                'stats' => [['label' => 'STR', 'value' => 1], ['label' => 'str', 'value' => 2]],
            ]))
            ->assertSessionHasErrors(['kind', 'name', 'hp', 'stats.1.label']);
    }

    public function test_the_compendium_shows_srd_and_own_creatures_only()
    {
        Creature::factory()->srd()->create(['name' => 'Goblin']);
        $user = User::factory()->create();
        Creature::factory()->for($user)->create(['name' => 'Mine']);
        Creature::factory()->create(['name' => 'Theirs']);

        $this->get('/compendium')->assertInertia(fn ($page) => $page->has('creatures', 1));

        $this->actingAs($user)->get('/compendium')->assertInertia(fn ($page) => $page
            ->has('creatures', 2)
            ->where('creatures.1.name', 'Mine')
            ->where('creatures.1.source', 'homebrew')
            ->where('creatures.0.source', 'srd'));
    }

    public function test_a_user_can_edit_and_delete_their_creature()
    {
        $user = User::factory()->create();
        $creature = Creature::factory()->for($user)->create();

        $this->actingAs($user)->get("/compendium/{$creature->id}/edit")->assertOk();
        $this->actingAs($user)->put("/compendium/{$creature->id}", $this->payload(['name' => 'Renamed']))->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $creature->fresh()->name);

        $this->actingAs($user)->delete("/compendium/{$creature->id}")->assertRedirect('/compendium');
        $this->assertModelMissing($creature);
    }

    public function test_srd_and_other_users_creatures_are_read_only()
    {
        $srd = Creature::factory()->srd()->create();
        $theirs = Creature::factory()->create();
        $this->actingAs(User::factory()->create());

        foreach ([$srd, $theirs] as $creature) {
            $this->get("/compendium/{$creature->id}/edit")->assertForbidden();
            $this->put("/compendium/{$creature->id}", $this->payload())->assertForbidden();
            $this->delete("/compendium/{$creature->id}")->assertForbidden();
            $this->assertModelExists($creature);
        }
    }

    public function test_duplicating_prefills_from_a_visible_creature_only()
    {
        $srd = Creature::factory()->srd()->create(['name' => 'Goblin']);
        $theirs = Creature::factory()->create(['name' => 'Secret']);
        $this->actingAs(User::factory()->create());

        $this->get("/compendium/create?from={$srd->id}")->assertInertia(fn ($page) => $page->where('template.name', 'Goblin'));
        $this->get("/compendium/create?from={$theirs->id}")->assertInertia(fn ($page) => $page->where('template', null));
    }
}
