<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\Creature;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * How the site is being used, as totals only: never anyone's names or content. Read by the
 * `turnkeeper:stats` command, and meant for an admin page later.
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
}
