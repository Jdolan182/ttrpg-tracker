<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\Creature;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How the site is being used, as totals only: never anyone's names or content. Read by the
 * `turnkeeper:stats` command and the admin page.
 */
class SiteStats
{
    /**
     * @return array{
     *     days: int,
     *     accounts: int, verified: int, new: int, active: int,
     *     creatures: int, creatureMakers: int, creaturesByKind: array<string, int>,
     *     encounters: int, encounterMakers: int, inCombat: int, biggestFight: int,
     *     campaigns: int, players: int, live: int,
     * }
     */
    public static function summary(int $days = 7): array
    {
        $since = now()->subDays($days);
        $homebrew = Creature::whereNotNull('user_id');

        // Active: made or changed a creature, encounter or campaign recently. Logging in alone doesn't count.
        $active = collect([Creature::class, Encounter::class, Campaign::class])
            ->flatMap(fn (string $model) => $model::query()->whereNotNull('user_id')->where('updated_at', '>=', $since)->distinct()->pluck('user_id'))
            ->unique()
            ->count();

        return [
            'days' => $days,
            'accounts' => User::count(),
            'verified' => User::whereNotNull('email_verified_at')->count(),
            'new' => User::where('created_at', '>=', $since)->count(),
            'active' => $active,
            'creatures' => (clone $homebrew)->count(),
            'creatureMakers' => (clone $homebrew)->distinct()->count('user_id'),
            'creaturesByKind' => (clone $homebrew)->groupBy('kind')->selectRaw('kind, count(*) as total')->pluck('total', 'kind')->map(fn ($n) => (int) $n)->all(),
            'encounters' => Encounter::count(),
            'encounterMakers' => Encounter::distinct()->count('user_id'),
            'inCombat' => Encounter::where('round', '>', 0)->count(),
            'biggestFight' => (int) Encounter::max(DB::raw('jsonb_array_length(combatants)')),
            'campaigns' => Campaign::count(),
            'players' => DB::table('campaign_user')->count(),
            'live' => Campaign::whereNotNull('live')->count(),
        ];
    }

    /**
     * Visits over the last `$days` days, from the daily totals App\Support\Visits keeps.
     *
     * @return array{
     *     history: list<array{day: string, visitors: int, accounts: int, signups: int, peakLiveTables: int}>,
     *     today: array{day: string, visitors: int, accounts: int, signups: int, peakLiveTables: int},
     *     averageVisitors7: float, averageVisitors30: float, trackedDays: int,
     *     activeAccounts: array{today: int, week: int, month: int},
     *     peakLiveTables: int,
     * }
     */
    public static function traffic(int $days = 30): array
    {
        $from = today()->subDays($days - 1);
        $stats = DB::table('daily_stats')->where('day', '>=', $from->toDateString())->get()->keyBy(fn ($row) => (string) $row->day);
        $signups = User::where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')->groupByRaw('DATE(created_at)')
            ->pluck('total', 'day');

        $history = [];
        for ($day = $from->copy(); $day->lte(today()); $day->addDay()) {
            $key = $day->toDateString();
            $row = $stats[$key] ?? null;
            $history[] = [
                'day' => $key,
                'visitors' => (int) ($row->visitors ?? 0),
                'accounts' => (int) ($row->accounts ?? 0),
                'signups' => (int) ($signups[$key] ?? 0),
                'peakLiveTables' => (int) ($row->peak_live_tables ?? 0),
            ];
        }

        // Averages only over the days counting has been running, so a new site isn't shown as quiet.
        $first = DB::table('daily_stats')->min('day');
        $trackedDays = $first ? (int) Carbon::parse($first)->diffInDays(today()) + 1 : 0;
        $average = function (int $window) use ($history, $trackedDays): float {
            $span = min($window, $trackedDays);

            return $span ? round(array_sum(array_column(array_slice($history, -$span), 'visitors')) / $span, 1) : 0.0;
        };

        return [
            'history' => $history,
            'today' => end($history),
            'averageVisitors7' => $average(7),
            'averageVisitors30' => $average(30),
            'trackedDays' => $trackedDays,
            'activeAccounts' => [
                'today' => User::where('last_active_at', '>=', today())->count(),
                'week' => User::where('last_active_at', '>=', today()->subDays(6))->count(),
                'month' => User::where('last_active_at', '>=', today()->subDays(29))->count(),
            ],
            'peakLiveTables' => (int) max(array_column($history, 'peakLiveTables')),
        ];
    }
}
