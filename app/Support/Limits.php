<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * What each plan lets someone make, from config/plans.php. Controllers call ensureCanCreate()
 * before creating anything that counts towards a limit.
 */
class Limits
{
    /**
     * How each limit is described in messages: [what you have, how to make room].
     */
    private const WORDING = [
        'creatures' => ['creatures', 'Delete one to make room.'],
        'encounters' => ['saved encounters', 'Delete one to make room.'],
        'campaigns' => ['campaigns', 'Delete one to make room.'],
        'campaigns_joined' => ['joined campaigns', 'Leave one to make room.'],
        'campaign_players' => ['players in a campaign', 'Remove a player to make room.'],
    ];

    /**
     * The plan's config entry, falling back to the default plan for an unknown or missing one.
     *
     * @return array{name: string, limits: array<string, int>}
     */
    public static function plan(User $user): array
    {
        $plans = config('plans.plans');

        return $plans[$user->plan] ?? $plans[config('plans.default')];
    }

    public static function limit(User $user, string $key): int
    {
        return (int) (self::plan($user)['limits'][$key] ?? 0);
    }

    /**
     * How many of something the user has made so far.
     */
    public static function usage(User $user, string $key): int
    {
        return match ($key) {
            'creatures' => $user->creatures()->count(),
            'encounters' => $user->encounters()->count(),
            'campaigns' => $user->campaigns()->count(),
            'campaigns_joined' => $user->joinedCampaigns()->count(),
            // Counted per campaign: see ensureCampaignHasRoom().
            default => 0,
        };
    }

    /**
     * Refuses another player when a campaign is full. The DM's plan decides how many fit,
     * so the message is worded for whoever is trying to join.
     *
     * @throws ValidationException
     */
    public static function ensureCampaignHasRoom(Campaign $campaign): void
    {
        $limit = self::limit($campaign->owner, 'campaign_players');
        if ($campaign->players()->count() < $limit) {
            return;
        }

        throw ValidationException::withMessages([
            'limit' => "This campaign is full: it already has {$limit} players. Ask the DM to make room.",
        ]);
    }

    /**
     * Usage against the limit for the things shown in the app, e.g. "12 of 25 creatures".
     *
     * @return array<string, array{used: int, limit: int}>
     */
    public static function summary(User $user): array
    {
        return collect(['creatures', 'encounters', 'campaigns', 'campaigns_joined'])
            ->mapWithKeys(fn (string $key) => [$key => ['used' => self::usage($user, $key), 'limit' => self::limit($user, $key)]])
            ->all();
    }

    /**
     * Refuses to add several at once (an import) when they wouldn't all fit.
     *
     * @throws ValidationException
     */
    public static function ensureRoomFor(User $user, string $key, int $count): void
    {
        $room = max(0, self::limit($user, $key) - self::usage($user, $key));
        if ($count <= $room) {
            return;
        }

        [$what, $makeRoom] = self::WORDING[$key];

        throw ValidationException::withMessages([
            'limit' => "There's only room for {$room} more {$what}, but this backup has {$count} new ".($count === 1 ? 'one' : 'ones').". {$makeRoom}",
        ]);
    }

    public static function canCreate(User $user, string $key): bool
    {
        return self::usage($user, $key) < self::limit($user, $key);
    }

    /**
     * Refuses to go over a limit, with a friendly message and no prices (subscriptions aren't live).
     *
     * @throws ValidationException
     */
    public static function ensureCanCreate(User $user, string $key): void
    {
        if (self::canCreate($user, $key)) {
            return;
        }

        [$what, $makeRoom] = self::WORDING[$key];
        $plan = strtolower(self::plan($user)['name']);

        throw ValidationException::withMessages([
            'limit' => "You've reached the {$plan} limit of ".self::limit($user, $key)." {$what}. {$makeRoom}",
        ]);
    }
}
