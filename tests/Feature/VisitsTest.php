<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Support\SiteStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Daily visitor totals for the admin page, and who can see that page.
 */
class VisitsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36';

    private function today(): object
    {
        return DB::table('daily_stats')->where('day', today()->toDateString())->first() ?? (object) ['visitors' => 0, 'accounts' => 0, 'peak_live_tables' => 0];
    }

    public function test_each_person_counts_once_a_day_whatever_they_open()
    {
        $this->withHeader('User-Agent', self::BROWSER);
        $this->get('/')->assertOk();
        $this->get('/compendium')->assertOk();
        $this->get('/privacy')->assertOk();
        $this->assertSame([1, 0], [$this->today()->visitors, $this->today()->accounts]);

        // A fresh session from the same browser (cookies cleared) is still the same person today…
        $this->flushSession();
        $this->get('/');
        $this->assertSame(1, $this->today()->visitors);

        // …but another browser (with its own session) is someone else.
        $this->flushSession();
        $this->withHeader('User-Agent', self::BROWSER.' Firefox')->get('/');
        $this->assertSame(2, $this->today()->visitors);
    }

    public function test_crawlers_and_health_checks_are_not_people()
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')->get('/');
        $this->withHeader('User-Agent', 'curl/8.5')->get('/compendium');
        $this->withHeader('User-Agent', self::BROWSER)->get('/up');
        $this->get('/robots.txt');

        $this->assertSame(0, $this->today()->visitors);
    }

    public function test_accounts_are_counted_and_marked_active_without_touching_the_account()
    {
        $user = User::factory()->create(['updated_at' => now()->subWeek()]);
        $updated = $user->updated_at;

        $this->withHeader('User-Agent', self::BROWSER)->actingAs($user)->get('/');
        $this->actingAs($user)->get('/compendium');

        $this->assertSame([1, 1], [$this->today()->visitors, $this->today()->accounts]);
        $user->refresh();
        $this->assertTrue($user->last_active_at->isToday());
        $this->assertEquals($updated, $user->updated_at);
        $this->assertSame(['today' => 1, 'week' => 1, 'month' => 1], SiteStats::traffic()['activeAccounts']);
    }

    public function test_nothing_identifying_is_kept()
    {
        $this->withHeader('User-Agent', self::BROWSER)->get('/', ['REMOTE_ADDR' => '203.0.113.9']);

        $keys = DB::table('visit_keys')->pluck('key');
        $this->assertCount(1, $keys);
        $this->assertStringNotContainsString('203.0.113.9', $keys[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $keys[0]);
        // The same visitor makes a different code on another day, so days can't be linked.
        $this->travel(1)->days();
        $this->flushSession();
        $this->get('/');
        $this->assertNotSame($keys[0], DB::table('visit_keys')->where('day', today()->toDateString())->value('key'));
    }

    public function test_the_busiest_moment_for_live_combat_is_kept()
    {
        $dm = User::factory()->create();
        $campaigns = Campaign::factory()->for($dm, 'owner')->count(3)->create();
        $fight = fn () => ['name' => 'Ambush', 'round' => 1, 'activeIndex' => 0, 'combatants' => [], 'log' => []];

        $this->actingAs($dm)->putJson("/campaigns/{$campaigns[0]->id}/combat", $fight())->assertNoContent();
        $this->actingAs($dm)->putJson("/campaigns/{$campaigns[1]->id}/combat", $fight())->assertNoContent();
        $this->assertSame(2, $this->today()->peak_live_tables);

        // One fight ending doesn't lower the day's peak.
        $this->actingAs($dm)->deleteJson("/campaigns/{$campaigns[0]->id}/combat");
        $this->actingAs($dm)->putJson("/campaigns/{$campaigns[1]->id}/combat", $fight());
        $this->assertSame(2, $this->today()->peak_live_tables);
    }

    public function test_averages_cover_only_the_days_counting_has_run()
    {
        DB::table('daily_stats')->insert([
            ['day' => today()->subDay()->toDateString(), 'visitors' => 10, 'accounts' => 2, 'peak_live_tables' => 1],
            ['day' => today()->toDateString(), 'visitors' => 4, 'accounts' => 1, 'peak_live_tables' => 3],
        ]);

        $traffic = SiteStats::traffic();
        $this->assertSame(2, $traffic['trackedDays']);
        $this->assertSame(7.0, $traffic['averageVisitors7']);
        $this->assertSame(3, $traffic['peakLiveTables']);
        $this->assertCount(30, $traffic['history']);
    }

    public function test_only_listed_verified_admins_can_open_the_admin_page()
    {
        config()->set('app.admin_emails', ['owner@example.com']);
        $owner = User::factory()->create(['email' => 'Owner@example.com']);

        $this->actingAs($owner)->get('/admin')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Index')
            ->has('traffic.history', 30)
            ->where('auth.isAdmin', true));

        $this->actingAs(User::factory()->create())->get('/admin')->assertNotFound();
        $this->actingAs(User::factory()->unverified()->create(['email' => 'owner@example.com']))->get('/admin');
        $this->get('/admin')->assertRedirect();
        // Signing up with the owner's address before they confirm it doesn't get anyone in.
        $squatter = User::factory()->unverified()->create(['email' => 'owner2@example.com']);
        config()->set('app.admin_emails', ['owner2@example.com']);
        $this->actingAs($squatter)->get('/admin')->assertRedirect(route('verification.notice'));
    }
}
