<?php

namespace App\Support;

/**
 * What players are allowed to see of a fight: hidden combatants are left out entirely, and enemy
 * HP follows the campaign's setting. Mirrors playerView() in resources/js/lib/playerView.ts, which
 * does the same for the DM's own screen and the same-computer second window.
 */
class PlayerView
{
    // Sides the players are on, or fighting alongside: they always see these HP numbers.
    private const FRIENDLY_SIDES = ['player', 'ally'];

    // Players get the latest part of the history only; the tracker sends no more than this.
    public const HISTORY_LIMIT = 150;

    /**
     * @param  array{name: string, round: int, activeIndex: int, combatants: list<array<string, mixed>>, log?: list<array<string, mixed>>}  $fight
     * @param  string  $enemyHp  One of Campaign::ENEMY_HP.
     * @return array<string, mixed>
     */
    public static function fromFight(array $fight, string $enemyHp): array
    {
        $combatants = [];
        foreach ($fight['combatants'] as $index => $combatant) {
            if (! empty($combatant['hidden'])) {
                continue;
            }
            $combatants[] = self::combatant($combatant, $index === $fight['activeIndex'], $enemyHp);
        }

        return [
            'name' => $fight['name'],
            'round' => $fight['round'],
            'combatants' => $combatants,
            'log' => self::history($fight['log'] ?? [], $fight['combatants'], $enemyHp),
        ];
    }

    /**
     * The history without anything players shouldn't know: entries about combatants while they
     * were hidden, setup, the DM's hide/reveal bookkeeping, and (unless enemy HP is exact) how much
     * enemies healed. Damage stays: players hear that at the table.
     *
     * Who was hidden when is worked out by walking back from now: a "hidden" entry means they
     * weren't hidden before it, a "revealed" one that they were.
     *
     * @param  list<array<string, mixed>>  $log
     * @param  list<array<string, mixed>>  $combatants
     * @return list<array<string, mixed>>
     */
    private static function history(array $log, array $combatants, string $enemyHp): array
    {
        $hidden = [];
        $sides = [];
        foreach ($combatants as $combatant) {
            $sides[$combatant['name']] = $combatant['side'];
            if (! empty($combatant['hidden'])) {
                $hidden[$combatant['name']] = true;
            }
        }

        $entries = [];
        foreach (array_reverse(array_slice($log, -self::HISTORY_LIMIT)) as $entry) {
            $targets = $entry['targets'] ?? [];

            if ($entry['type'] === 'hidden' || $entry['type'] === 'revealed') {
                foreach ($targets as $name) {
                    if ($entry['type'] === 'hidden') {
                        unset($hidden[$name]);
                    } else {
                        $hidden[$name] = true;
                    }
                }

                continue;
            }
            if ($entry['round'] < 1) {
                continue;
            }

            // A hidden combatant's turn still happened; players just don't learn whose it was.
            if ($entry['type'] === 'turn' && isset($hidden[$entry['actor'] ?? ''])) {
                unset($entry['actor']);
                $entries[] = $entry;

                continue;
            }
            if (isset($hidden[$entry['actor'] ?? ''])) {
                continue;
            }
            // E.g. a fireball that also hit someone hidden: keep it, just without them.
            if ($targets) {
                $targets = array_values(array_filter($targets, fn (string $name) => ! isset($hidden[$name])));
                if (! $targets) {
                    continue;
                }
                $entry['targets'] = $targets;
            }

            $isHealing = in_array($entry['type'], ['heal', 'temp_hp'], true) || ($entry['type'] === 'action' && ($entry['effect'] ?? null) === 'heal');
            $onEnemies = array_filter($targets, fn (string $name) => ! in_array($sides[$name] ?? null, self::FRIENDLY_SIDES, true));
            if ($isHealing && $onEnemies && $enemyHp !== 'exact') {
                unset($entry['amount'], $entry['effect']);
            }

            $entries[] = $entry;
        }

        return array_reverse($entries);
    }

    /**
     * @param  array<string, mixed>  $combatant
     * @return array<string, mixed>
     */
    private static function combatant(array $combatant, bool $active, string $enemyHp): array
    {
        $status = self::status($combatant);
        $friendly = in_array($combatant['side'], self::FRIENDLY_SIDES, true);
        $showNumbers = $friendly || $enemyHp === 'exact';
        // With HP hidden, players still see an enemy drop: that's plain at the table anyway.
        $showStatus = $showNumbers || $enemyHp === 'bands' || $status === 'down';

        $durations = $combatant['durations'] ?? [];

        return [
            'id' => $combatant['id'],
            'name' => $combatant['name'],
            'side' => $combatant['side'],
            'initiative' => $combatant['initiative'],
            'active' => $active,
            'hp' => $showNumbers ? $combatant['hp'] : null,
            'maxHp' => $showNumbers ? $combatant['maxHp'] : null,
            'tempHp' => $showNumbers ? ($combatant['tempHp'] ?? 0) : null,
            'status' => $showStatus ? $status : null,
            'conditions' => array_map(fn (string $name) => ['name' => $name, 'rounds' => $durations[$name] ?? null], $combatant['conditions']),
            'concentrating' => ! empty($combatant['concentrating']),
        ];
    }

    /**
     * healthy, bloodied (half HP or less), down (0 HP), and for player characters at 0 HP:
     * dying, stable or dead, from their death saves.
     *
     * @param  array<string, mixed>  $combatant
     */
    private static function status(array $combatant): string
    {
        if ($combatant['hp'] > 0) {
            return $combatant['hp'] * 2 <= $combatant['maxHp'] ? 'bloodied' : 'healthy';
        }
        if ($combatant['side'] !== 'player') {
            return 'down';
        }

        $saves = $combatant['deathSaves'] ?? ['successes' => 0, 'failures' => 0];

        return match (true) {
            $saves['failures'] >= 3 => 'dead',
            $saves['successes'] >= 3 => 'stable',
            default => 'dying',
        };
    }
}
