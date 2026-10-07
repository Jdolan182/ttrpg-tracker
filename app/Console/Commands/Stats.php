<?php

namespace App\Console\Commands;

use App\Support\SiteStats;
use Illuminate\Console\Command;

class Stats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'turnkeeper:stats {--days=7 : What counts as recent}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'How many accounts there are and what people have made (totals only)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $s = SiteStats::summary($days);
        $recent = $days === 1 ? 'today' : "in the last {$days} days";
        $kinds = collect($s['creaturesByKind'])->map(fn (int $n, string $kind) => "{$n} {$kind}")->implode(', ');

        $this->table(['', 'Total', ''], [
            ['Accounts', $s['accounts'], "{$s['verified']} verified, {$s['new']} new {$recent}"],
            ['Active', $s['active'], "made or changed something {$recent}"],
            ['Homebrew creatures', $s['creatures'], "by {$s['creatureMakers']} accounts".($kinds ? " ({$kinds})" : '')],
            ['Saved encounters', $s['encounters'], "by {$s['encounterMakers']} accounts, {$s['inCombat']} saved mid-fight"],
            ['Biggest fight', $s['biggestFight'], 'combatants'],
            ['Campaigns', $s['campaigns'], "{$s['players']} players joined, {$s['live']} with a fight live now"],
        ]);

        $t = SiteStats::traffic(30);
        $this->table(['Visits', 'Total', ''], [
            ['Visitors today', $t['today']['visitors'], "{$t['today']['accounts']} logged in"],
            ['Daily visitors', $t['averageVisitors7'], "average over 7 days ({$t['averageVisitors30']} over 30)"],
            ['Accounts active', $t['activeAccounts']['month'], "this month ({$t['activeAccounts']['week']} this week, {$t['activeAccounts']['today']} today)"],
            ['Live tables at once', $t['peakLiveTables'], 'most in the last 30 days'],
        ]);
        if ($t['trackedDays'] < 30) {
            $this->line("  Visits have been counted for {$t['trackedDays']} day(s); the averages cover that time.");
        }

        return self::SUCCESS;
    }
}
