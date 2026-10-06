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

    public function test_the_srd_monsters_come_with_their_limits_in_the_apps_own_terms()
    {
        $this->seed(SrdCreatureSeeder::class);
        $this->assertGreaterThan(300, Creature::whereNull('user_id')->count());

        $dragon = collect(Creature::where('name', 'Adult Red Dragon')->sole()->toFrontend());
        $actions = collect($dragon['actions'])->keyBy('name');
        $this->assertEquals(['die' => 6, 'min' => 5], $actions['Fire Breath']['recharge']);
        $this->assertSame([3, 'day'], [$actions['Legendary Resistance']['uses'], $actions['Legendary Resistance']['per']]);
        $this->assertSame(['Legendary actions', 2], [$actions['Wing Attack']['resource'], $actions['Wing Attack']['cost']]);
        $this->assertEquals([['name' => 'Legendary actions', 'max' => 3, 'per' => 'turn']], $dragon['resources']);

        $lich = Creature::where('name', 'Lich')->sole()->toFrontend();
        // assertEquals rather than assertContains: jsonb keeps object keys in its own order.
        $this->assertEquals(['name' => 'Level 9 spell slots', 'max' => 1, 'per' => 'day'], collect($lich['resources'])->firstWhere('name', 'Level 9 spell slots'));
        $this->assertSame('npc', Creature::where('name', 'Mage')->value('kind'));
    }

    public function test_the_initiative_bonus_is_optional_and_kept_when_set()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/compendium', $this->payload(['name' => 'Quickling', 'initiativeBonus' => '7']))->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/compendium', $this->payload(['name' => 'Slug']))->assertSessionHasNoErrors();
        // 0 is a real bonus, not the same as leaving it blank.
        $this->actingAs($user)->post('/compendium', $this->payload(['name' => 'Steady', 'initiativeBonus' => 0]))->assertSessionHasNoErrors();

        $bonuses = $user->creatures()->pluck('initiative_bonus', 'name');
        $this->assertSame(7, $bonuses['Quickling']);
        $this->assertNull($bonuses['Slug']);
        $this->assertSame(0, $bonuses['Steady']);
        $this->assertSame(7, $user->creatures()->where('name', 'Quickling')->sole()->toFrontend()['initiativeBonus']);

        $this->actingAs($user)->post('/compendium', $this->payload(['name' => 'Too fast', 'initiativeBonus' => 500]))->assertSessionHasErrors('initiativeBonus');
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
        // assertEquals, not assertSame: jsonb stores object keys in its own order.
        $this->assertEquals([[
            'name' => 'Hex Bolt',
            'description' => 'Ranged spell attack: +5 to hit. Hit: 7 necrotic damage.',
            'uses' => null,
            'per' => null,
            'recharge' => null,
            'cooldown' => null,
            'resource' => null,
            'cost' => null,
        ]], $creature->actions);
        $this->assertSame([], $creature->resources);
    }

    public function test_actions_can_recharge_cool_down_or_spend_resources()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/compendium', $this->payload([
            'resources' => [
                ['name' => 'Legendary actions', 'max' => '3', 'per' => 'turn'],
                ['name' => 'Mana', 'max' => 10, 'per' => 'day'],
            ],
            'actions' => [
                ['name' => 'Fire Breath', 'description' => 'Whoosh.', 'recharge' => ['die' => '6', 'min' => '5']],
                ['name' => 'Quake', 'description' => 'Rumble.', 'cooldown' => '1D4'],
                ['name' => 'Wing Attack', 'description' => 'Flap.', 'resource' => 'Legendary actions', 'cost' => '2'],
            ],
        ]))->assertSessionHasNoErrors();

        $creature = $user->creatures()->sole();
        [$breath, $quake, $wing] = $creature->toFrontend()['actions'];
        $this->assertEquals(['die' => 6, 'min' => 5], $breath['recharge']);
        $this->assertSame('1d4', $quake['cooldown']);
        $this->assertSame(['Legendary actions', 2], [$wing['resource'], $wing['cost']]);
        $this->assertNull($wing['uses']);
        $this->assertEquals(['name' => 'Mana', 'max' => 10, 'per' => 'day'], $creature->toFrontend()['resources'][1]);
    }

    public function test_each_action_has_one_kind_of_limit_and_only_spends_its_own_resources()
    {
        $this->actingAs(User::factory()->create())
            ->post('/compendium', $this->payload([
                'resources' => [
                    ['name' => 'Ki', 'max' => 3, 'per' => 'encounter'],
                    ['name' => 'ki', 'max' => 0, 'per' => 'week'],
                ],
                'actions' => [
                    ['name' => 'Both', 'description' => 'x', 'uses' => 1, 'per' => 'day', 'recharge' => ['die' => 6, 'min' => 5]],
                    ['name' => 'Impossible', 'description' => 'x', 'recharge' => ['die' => 6, 'min' => 7]],
                    ['name' => 'Vague', 'description' => 'x', 'cooldown' => 'a while'],
                    ['name' => 'Borrowed', 'description' => 'x', 'resource' => 'Spell slots', 'cost' => 1],
                    ['name' => 'Free', 'description' => 'x', 'resource' => 'Ki'],
                ],
            ]))
            ->assertSessionHasErrors([
                'actions.0.uses', 'actions.1.recharge.min', 'actions.2.cooldown', 'actions.3.resource', 'actions.4.cost',
                'resources.1.name', 'resources.1.max', 'resources.1.per',
            ]);
    }

    public function test_actions_can_have_a_usage_limit()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/compendium', $this->payload(['actions' => [
            ['name' => 'Hex Bolt', 'description' => 'Zap.', 'uses' => '3', 'per' => 'day'],
            ['name' => 'Legendary Swipe', 'description' => 'Swipe.', 'uses' => 1, 'per' => 'round'],
            // A period without a count is dropped: the action is unlimited.
            ['name' => 'Bite', 'description' => 'Chomp.', 'uses' => null, 'per' => 'turn'],
        ]]))->assertSessionHasNoErrors();

        $actions = $user->creatures()->sole()->actions;
        $this->assertSame([3, 'day'], [$actions[0]['uses'], $actions[0]['per']]);
        $this->assertSame([1, 'round'], [$actions[1]['uses'], $actions[1]['per']]);
        $this->assertSame([null, null], [$actions[2]['uses'], $actions[2]['per']]);
    }

    public function test_action_limits_and_names_are_validated()
    {
        $this->actingAs(User::factory()->create())
            ->post('/compendium', $this->payload(['actions' => [
                ['name' => 'Bite', 'description' => 'Chomp.', 'uses' => 0, 'per' => 'day'],
                ['name' => 'bite', 'description' => 'Chomp again.'],
                ['name' => 'Roar', 'description' => 'Loud.', 'uses' => 2, 'per' => 'fortnight'],
                ['name' => 'Stomp', 'description' => 'Thud.', 'uses' => 2],
            ]]))
            ->assertSessionHasErrors(['actions.0.uses', 'actions.1.name', 'actions.2.per', 'actions.3.per']);
    }

    public function test_actions_saved_before_limits_existed_read_as_unlimited()
    {
        $creature = Creature::factory()->srd()->create(['actions' => [['name' => 'Slam', 'description' => 'Thud.']]]);

        $this->get('/compendium')->assertInertia(fn ($page) => $page
            ->where('creatures.0.id', $creature->id)
            ->where('creatures.0.actions.0.uses', null)
            ->where('creatures.0.actions.0.per', null));
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
