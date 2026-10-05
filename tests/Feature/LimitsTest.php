<?php

namespace Tests\Feature;

use App\Models\Creature;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Small numbers so the tests don't depend on whatever the real config says.
        config()->set('plans.plans.free.limits.creatures', 2);
        config()->set('plans.plans.free.limits.encounters', 1);
        config()->set('plans.plans.pro.limits.creatures', 5);
    }

    private function creaturePayload(string $name = 'Bandit'): array
    {
        return [
            'kind' => 'monster', 'name' => $name, 'summary' => '', 'rating' => '', 'hp' => 10, 'ac' => 12, 'speed' => '',
            'stats' => [], 'traits' => [], 'actions' => [],
        ];
    }

    private function encounterPayload(): array
    {
        return ['name' => 'Prep', 'round' => 0, 'activeIndex' => 0, 'combatants' => []];
    }

    public function test_new_accounts_are_on_the_free_plan()
    {
        $this->assertSame('free', User::factory()->create()->fresh()->plan);
    }

    public function test_creating_creatures_stops_at_the_limit()
    {
        $user = User::factory()->create();
        Creature::factory()->count(2)->for($user)->create();

        $this->actingAs($user)->post('/compendium', $this->creaturePayload())
            ->assertSessionHasErrors(['limit' => "You've reached the free limit of 2 creatures. Delete one to make room."]);

        $this->assertSame(2, $user->creatures()->count());
    }

    public function test_srd_creatures_and_other_peoples_dont_count()
    {
        $user = User::factory()->create();
        Creature::factory()->count(3)->srd()->create();
        Creature::factory()->count(3)->create();
        Creature::factory()->for($user)->create();

        $this->actingAs($user)->post('/compendium', $this->creaturePayload())->assertSessionHasNoErrors();
        $this->assertSame(2, $user->creatures()->count());
    }

    public function test_editing_at_the_limit_is_still_allowed()
    {
        $user = User::factory()->create();
        [$creature] = Creature::factory()->count(2)->for($user)->create();
        $encounter = Encounter::factory()->for($user)->create();

        $this->actingAs($user)->put("/compendium/{$creature->id}", $this->creaturePayload('Renamed'))->assertSessionHasNoErrors();
        $this->actingAs($user)->put("/encounters/{$encounter->id}", $this->encounterPayload())->assertSessionHasNoErrors();
    }

    public function test_saving_new_encounters_stops_at_the_limit()
    {
        $user = User::factory()->create();
        Encounter::factory()->for($user)->create();

        $this->actingAs($user)->post('/encounters', $this->encounterPayload())
            ->assertSessionHasErrors(['limit' => "You've reached the free limit of 1 saved encounters. Delete one to make room."]);

        $this->assertSame(1, $user->encounters()->count());
    }

    public function test_pro_accounts_get_the_pro_limits()
    {
        $user = User::factory()->create();
        $user->forceFill(['plan' => 'pro'])->save();
        Creature::factory()->count(4)->for($user)->create();

        $this->actingAs($user)->post('/compendium', $this->creaturePayload())->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/compendium', $this->creaturePayload('Another'))
            ->assertSessionHasErrors(['limit' => "You've reached the pro limit of 5 creatures. Delete one to make room."]);
    }

    public function test_an_unknown_plan_falls_back_to_the_default()
    {
        $user = User::factory()->create();
        $user->forceFill(['plan' => 'retired-plan'])->save();
        Creature::factory()->count(2)->for($user)->create();

        $this->actingAs($user)->post('/compendium', $this->creaturePayload())->assertSessionHasErrors('limit');
    }

    public function test_usage_is_shared_with_pages_but_the_plan_is_not()
    {
        $user = User::factory()->create();
        Creature::factory()->for($user)->create();

        $this->actingAs($user)->get('/compendium')->assertInertia(fn ($page) => $page
            ->where('limits.creatures', ['used' => 1, 'limit' => 2])
            ->where('limits.encounters', ['used' => 0, 'limit' => 1])
            ->missing('auth.user.plan'));

        auth()->logout();
        $this->get('/compendium')->assertInertia(fn ($page) => $page->where('limits', null));
    }

    public function test_the_plan_cannot_be_changed_through_the_profile_form()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', ['name' => 'Sneaky', 'email' => $user->email, 'plan' => 'pro']);

        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_the_plan_set_command()
    {
        $user = User::factory()->create(['email' => 'dm@example.com']);

        $this->artisan('plan:set', ['email' => 'dm@example.com', 'plan' => 'pro'])
            ->expectsOutput('dm@example.com is now on the pro plan.')
            ->assertSuccessful();
        $this->assertSame('pro', $user->fresh()->plan);

        $this->artisan('plan:set', ['email' => 'dm@example.com', 'plan' => 'platinum'])->assertFailed();
        $this->artisan('plan:set', ['email' => 'nobody@example.com', 'plan' => 'pro'])->assertFailed();
        $this->assertSame('pro', $user->fresh()->plan);

        // Without a plan it just shows the current one.
        $this->artisan('plan:set', ['email' => 'dm@example.com'])->assertSuccessful();
    }
}
