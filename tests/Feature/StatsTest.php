<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Creature;
use App\Models\Encounter;
use App\Models\User;
use App\Support\SiteStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_accounts_and_what_they_made()
    {
        $dm = User::factory()->create();
        $player = User::factory()->unverified()->create();
        User::factory()->create(['created_at' => now()->subMonth()]);

        Creature::factory()->srd()->count(3)->create();
        Creature::factory()->for($dm)->count(2)->create(['kind' => 'monster']);
        Creature::factory()->for($dm)->create(['kind' => 'player']);
        Encounter::factory()->for($dm)->create(['round' => 2, 'combatants' => array_fill(0, 7, ['id' => 'x'])]);
        $campaign = Campaign::factory()->for($dm, 'owner')->create();
        $campaign->players()->attach($player);

        $s = SiteStats::summary();

        $this->assertSame(3, $s['accounts']);
        $this->assertSame(2, $s['verified']);
        $this->assertSame(2, $s['new']);
        $this->assertSame(1, $s['active']);
        // SRD monsters aren't anyone's creations.
        $this->assertSame(3, $s['creatures']);
        $this->assertSame(1, $s['creatureMakers']);
        $this->assertEqualsCanonicalizing(['monster' => 2, 'player' => 1], $s['creaturesByKind']);
        $this->assertSame([1, 1, 7], [$s['encounters'], $s['inCombat'], $s['biggestFight']]);
        $this->assertSame([1, 1], [$s['campaigns'], $s['players']]);
    }

    public function test_the_command_prints_the_totals()
    {
        User::factory()->create();

        $this->artisan('turnkeeper:stats')
            ->expectsOutputToContain('Accounts')
            ->expectsOutputToContain('made or changed something in the last 7 days')
            ->assertSuccessful();

        // An empty site has no fights at all: shown as 0, not an error.
        $this->artisan('turnkeeper:stats --days=1')->expectsOutputToContain('today')->assertSuccessful();
    }
}
