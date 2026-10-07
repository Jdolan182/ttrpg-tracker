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

        return self::SUCCESS;
    }
}
