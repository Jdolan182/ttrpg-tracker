<?php

namespace App\Support;

use App\Models\Creature;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Validation and normalisation for an encounter as the tracker sends it
 * (see StoredTracker / Encounter in resources/js). Used when saving and when
 * importing a guest's fight on registration.
 */
class EncounterPayload
{
    public const MAX_COMBATANTS = 100;

    public const SIDES = ['player', 'ally', 'neutral', 'enemy'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(int $minCombatants = 0): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'round' => ['required', 'integer', 'min:1', 'max:10000'],
            'activeIndex' => ['required', 'integer', 'min:0'],
            'combatants' => ['present', 'array', 'min:'.$minCombatants, 'max:'.self::MAX_COMBATANTS],
            'combatants.*.id' => ['required', 'string', 'max:50', 'distinct'],
            'combatants.*.creatureId' => ['required', 'integer'],
            'combatants.*.name' => ['required', 'string', 'max:100'],
            // Optional so fights saved before sides existed still load; toAttributes() fills it in.
            'combatants.*.side' => ['nullable', Rule::in(self::SIDES)],
            'combatants.*.initiative' => ['required', 'integer', 'between:-100,1000'],
            'combatants.*.hp' => ['required', 'integer', 'min:0', 'max:100000'],
            'combatants.*.maxHp' => ['required', 'integer', 'min:1', 'max:100000'],
            'combatants.*.ac' => ['required', 'integer', 'min:0', 'max:1000'],
            'combatants.*.conditions' => ['present', 'array', 'max:30'],
            'combatants.*.conditions.*' => ['string', 'max:50'],
        ];
    }

    /**
     * Checks the rules can't express: the active turn exists, every creature is one the user
     * may use, and only player characters are on the player side.
     *
     * @param  array<string, mixed>  $data  Already passed rules().
     * @return array<string, string> Field => message.
     */
    public static function problems(array $data, ?User $user): array
    {
        $problems = [];
        $count = count($data['combatants']);

        if ($data['activeIndex'] > max(0, $count - 1)) {
            $problems['activeIndex'] = 'The active turn must be one of the combatants.';
        }

        $kinds = self::creatureKinds($data, $user);
        $creatureIds = array_unique(array_column($data['combatants'], 'creatureId'));
        if (count($kinds) !== count($creatureIds)) {
            $problems['combatants'] = 'One or more creatures in this encounter no longer exist.';

            return $problems;
        }

        foreach ($data['combatants'] as $index => $combatant) {
            $side = $combatant['side'] ?? null;
            $isPlayer = $kinds[$combatant['creatureId']] === 'player';
            if ($side !== null && $isPlayer !== ($side === 'player')) {
                $problems["combatants.{$index}.side"] = $isPlayer
                    ? 'Player characters are always on the player side.'
                    : 'Only player characters can be on the player side.';
            }
        }

        return $problems;
    }

    /**
     * Model attributes, keeping only known keys, never letting current HP exceed max HP,
     * and giving any combatant without a side the default for its creature.
     *
     * @param  array<string, mixed>  $data  Already passed rules() and problems().
     * @return array<string, mixed>
     */
    public static function toAttributes(array $data, ?User $user): array
    {
        $kinds = self::creatureKinds($data, $user);

        return [
            'name' => $data['name'],
            'round' => $data['round'],
            'active_index' => $data['activeIndex'],
            'combatants' => array_map(fn (array $combatant) => [
                'id' => $combatant['id'],
                'creatureId' => $combatant['creatureId'],
                'name' => $combatant['name'],
                'side' => $combatant['side'] ?? self::defaultSide($kinds[$combatant['creatureId']] ?? 'monster'),
                'initiative' => $combatant['initiative'],
                'hp' => min($combatant['hp'], $combatant['maxHp']),
                'maxHp' => $combatant['maxHp'],
                'ac' => $combatant['ac'],
                'conditions' => array_values(array_unique($combatant['conditions'])),
            ], $data['combatants']),
        ];
    }

    /**
     * Where a creature starts: players on the player side, NPCs neutral, monsters as enemies.
     * Mirrors defaultSide() in resources/js/lib/encounter.ts.
     */
    public static function defaultSide(string $kind): string
    {
        return match ($kind) {
            'player' => 'player',
            'npc' => 'neutral',
            default => 'enemy',
        };
    }

    /**
     * Kind of each referenced creature the user can see, keyed by id.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private static function creatureKinds(array $data, ?User $user): array
    {
        $creatureIds = array_values(array_unique(array_column($data['combatants'], 'creatureId')));

        return $creatureIds === []
            ? []
            : Creature::visibleTo($user)->whereIn('id', $creatureIds)->pluck('kind', 'id')->all();
    }
}
