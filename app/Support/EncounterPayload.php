<?php

namespace App\Support;

use App\Models\Creature;
use App\Models\User;

/**
 * Validation and normalisation for an encounter as the tracker sends it
 * (see StoredTracker / Encounter in resources/js). Used when saving and when
 * importing a guest's fight on registration.
 */
class EncounterPayload
{
    public const MAX_COMBATANTS = 100;

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
            'combatants.*.initiative' => ['required', 'integer', 'between:-100,1000'],
            'combatants.*.hp' => ['required', 'integer', 'min:0', 'max:100000'],
            'combatants.*.maxHp' => ['required', 'integer', 'min:1', 'max:100000'],
            'combatants.*.ac' => ['required', 'integer', 'min:0', 'max:1000'],
            'combatants.*.conditions' => ['present', 'array', 'max:30'],
            'combatants.*.conditions.*' => ['string', 'max:50'],
        ];
    }

    /**
     * Checks the rules can't express: the active turn exists, and every creature is one the user may use.
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

        $creatureIds = array_values(array_unique(array_column($data['combatants'], 'creatureId')));
        $visible = Creature::visibleTo($user)->whereIn('id', $creatureIds)->count();
        if ($visible !== count($creatureIds)) {
            $problems['combatants'] = 'One or more creatures in this encounter no longer exist.';
        }

        return $problems;
    }

    /**
     * Model attributes, keeping only known keys and never letting current HP exceed max HP.
     *
     * @param  array<string, mixed>  $data  Already passed rules() and problems().
     * @return array<string, mixed>
     */
    public static function toAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'round' => $data['round'],
            'active_index' => $data['activeIndex'],
            'combatants' => array_map(fn (array $combatant) => [
                'id' => $combatant['id'],
                'creatureId' => $combatant['creatureId'],
                'name' => $combatant['name'],
                'initiative' => $combatant['initiative'],
                'hp' => min($combatant['hp'], $combatant['maxHp']),
                'maxHp' => $combatant['maxHp'],
                'ac' => $combatant['ac'],
                'conditions' => array_values(array_unique($combatant['conditions'])),
            ], $data['combatants']),
        ];
    }
}
