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

    public const MAX_LOG_ENTRIES = 1000;

    // Mirrors LogEntryType in resources/js/lib/combatLog.ts.
    public const LOG_TYPES = [
        'combat_started', 'round', 'turn', 'damage', 'heal', 'down', 'defeated', 'revived',
        'condition_on', 'condition_off', 'side', 'action', 'joined', 'removed', 'moved', 'sorted', 'initiative_rolled',
        'condition_expired', 'temp_hp', 'concentration', 'death_save', 'stabilized', 'died', 'hidden', 'revealed',
        'combat_ended', 'recharged', 'not_recharged',
    ];

    // History only the DM sees (PlayerView leaves it out): e.g. whether an enemy's big attack is back.
    public const DM_ONLY_LOG_TYPES = ['recharged', 'not_recharged'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(int $minCombatants = 0): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // A campaign the user runs; checked in problems().
            'campaignId' => ['nullable', 'integer'],
            // 0 while the DM is still setting up, before combat starts.
            'round' => ['required', 'integer', 'min:0', 'max:10000'],
            'activeIndex' => ['required', 'integer', 'min:0'],
            'combatants' => ['present', 'array', 'min:'.$minCombatants, 'max:'.self::MAX_COMBATANTS],
            'combatants.*.id' => ['required', 'string', 'max:50', 'distinct'],
            // Null for a quick-added combatant (e.g. a guest's player character) that isn't in the compendium.
            'combatants.*.creatureId' => ['present', 'nullable', 'integer'],
            'combatants.*.name' => ['required', 'string', 'max:100'],
            // Optional so fights saved before sides existed still load; toAttributes() fills it in.
            'combatants.*.side' => ['nullable', Rule::in(self::SIDES)],
            // Null until it's rolled or entered (players roll their own).
            'combatants.*.initiative' => ['present', 'nullable', 'integer', 'between:-100,1000'],
            'combatants.*.hp' => ['required', 'integer', 'min:0', 'max:100000'],
            'combatants.*.maxHp' => ['required', 'integer', 'min:1', 'max:100000'],
            'combatants.*.ac' => ['required', 'integer', 'min:0', 'max:1000'],
            'combatants.*.conditions' => ['present', 'array', 'max:30'],
            'combatants.*.conditions.*' => ['string', 'max:50'],
            // Quick-added combatants can carry their own stats, since they have no creature to take them from.
            'combatants.*.stats' => ['nullable', 'array', 'max:30'],
            'combatants.*.stats.*.label' => ['required', 'string', 'max:20'],
            'combatants.*.stats.*.value' => ['required', 'integer', 'between:-1000,1000'],
            // Limited actions' state, keyed by action name: times used, 1 for a spent recharge, or rounds of cooldown left.
            'combatants.*.used' => ['nullable', 'array', 'max:50'],
            'combatants.*.used.*' => ['integer', 'min:0', 'max:999'],
            // How much of each of the creature's resources has been spent, keyed by resource name.
            'combatants.*.spent' => ['nullable', 'array', 'max:20'],
            'combatants.*.spent.*' => ['integer', 'min:0', 'max:999'],
            // Rounds left on timed conditions, keyed by condition name; counted down at the end of their turn.
            'combatants.*.durations' => ['nullable', 'array', 'max:30'],
            'combatants.*.durations.*' => ['integer', 'min:1', 'max:1000'],
            'combatants.*.tempHp' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'combatants.*.concentrating' => ['nullable', 'boolean'],
            // Hidden from the (future) player view; the DM still sees them.
            'combatants.*.hidden' => ['nullable', 'boolean'],
            'combatants.*.deathSaves' => ['nullable', 'array'],
            'combatants.*.deathSaves.successes' => ['required_with:combatants.*.deathSaves', 'integer', 'between:0,3'],
            'combatants.*.deathSaves.failures' => ['required_with:combatants.*.deathSaves', 'integer', 'between:0,3'],
            // The history is display-only, but still bounded and shaped.
            'log' => ['nullable', 'array', 'max:'.self::MAX_LOG_ENTRIES],
            'log.*.id' => ['required', 'string', 'max:50'],
            'log.*.at' => ['required', 'date'],
            'log.*.round' => ['required', 'integer', 'min:0', 'max:10000'],
            'log.*.type' => ['required', Rule::in(self::LOG_TYPES)],
            'log.*.actor' => ['nullable', 'string', 'max:100'],
            'log.*.targets' => ['nullable', 'array', 'max:'.self::MAX_COMBATANTS],
            'log.*.targets.*' => ['string', 'max:100'],
            'log.*.amount' => ['nullable', 'integer', 'between:-100000,100000'],
            'log.*.effect' => ['nullable', Rule::in(['damage', 'heal'])],
            'log.*.detail' => ['nullable', 'string', 'max:200'],
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

        if (! empty($data['campaignId']) && ! $user?->campaigns()->whereKey($data['campaignId'])->exists()) {
            $problems['campaignId'] = 'That campaign no longer exists.';
        }

        if ($data['activeIndex'] > max(0, $count - 1)) {
            $problems['activeIndex'] = 'The active turn must be one of the combatants.';
        }

        $kinds = self::creatureKinds($data, $user);
        if (count($kinds) !== count(self::creatureIds($data))) {
            $problems['combatants'] = 'One or more creatures in this encounter no longer exist.';

            return $problems;
        }

        foreach ($data['combatants'] as $index => $combatant) {
            // Quick-added combatants have no creature to check against, so any side goes.
            if ($combatant['creatureId'] === null) {
                continue;
            }

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
            'campaign_id' => $data['campaignId'] ?? null,
            'round' => $data['round'],
            'active_index' => $data['activeIndex'],
            'combatants' => array_map(fn (array $combatant) => self::combatantAttributes($combatant, $kinds), $data['combatants']),
            'log' => array_map(fn (array $entry) => array_filter([
                'id' => $entry['id'],
                'at' => $entry['at'],
                'round' => $entry['round'],
                'type' => $entry['type'],
                'actor' => $entry['actor'] ?? null,
                'targets' => $entry['targets'] ?? null,
                'amount' => $entry['amount'] ?? null,
                'effect' => $entry['effect'] ?? null,
                'detail' => $entry['detail'] ?? null,
            ], fn ($value) => $value !== null), $data['log'] ?? []),
        ];
    }

    /**
     * One combatant as stored. Optional state (temp HP, concentration, death saves, timers…) is only
     * kept when it's set, so a plain combatant stays small.
     *
     * @param  array<string, mixed>  $combatant
     * @param  array<int, string>  $kinds
     * @return array<string, mixed>
     */
    private static function combatantAttributes(array $combatant, array $kinds): array
    {
        $conditions = array_values(array_unique($combatant['conditions']));

        $attributes = [
            'id' => $combatant['id'],
            'creatureId' => $combatant['creatureId'],
            'name' => $combatant['name'],
            'side' => $combatant['side'] ?? self::defaultSide($kinds[$combatant['creatureId'] ?? 0] ?? 'monster'),
            'initiative' => $combatant['initiative'],
            'hp' => min($combatant['hp'], $combatant['maxHp']),
            'maxHp' => $combatant['maxHp'],
            'ac' => $combatant['ac'],
            'conditions' => $conditions,
            // An object keyed by action name; cast so an empty one stays {} rather than [].
            'used' => (object) array_filter(array_map('intval', $combatant['used'] ?? []), fn (int $count) => $count > 0),
        ];

        // Only quick-added combatants keep stats of their own; the rest use their creature's.
        if ($combatant['creatureId'] === null && ! empty($combatant['stats'])) {
            $attributes['stats'] = array_map(fn (array $stat) => ['label' => $stat['label'], 'value' => (int) $stat['value']], $combatant['stats']);
        }

        // Timers only make sense for conditions the combatant actually has.
        $durations = array_intersect_key(array_map('intval', $combatant['durations'] ?? []), array_flip($conditions));
        if ($durations) {
            $attributes['durations'] = $durations;
        }

        $spent = array_filter(array_map('intval', $combatant['spent'] ?? []), fn (int $amount) => $amount > 0);
        if ($spent) {
            $attributes['spent'] = $spent;
        }

        if (! empty($combatant['tempHp'])) {
            $attributes['tempHp'] = (int) $combatant['tempHp'];
        }
        if (! empty($combatant['concentrating'])) {
            $attributes['concentrating'] = true;
        }
        if (! empty($combatant['hidden'])) {
            $attributes['hidden'] = true;
        }
        // Death saves are a player-character thing, and only matter once they're down.
        if (isset($combatant['deathSaves']) && $attributes['side'] === 'player') {
            $attributes['deathSaves'] = [
                'successes' => (int) $combatant['deathSaves']['successes'],
                'failures' => (int) $combatant['deathSaves']['failures'],
            ];
        }

        return $attributes;
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
        $creatureIds = self::creatureIds($data);

        return $creatureIds === []
            ? []
            : Creature::visibleTo($user)->whereIn('id', $creatureIds)->pluck('kind', 'id')->all();
    }

    /**
     * The distinct compendium creatures the encounter uses; quick-added combatants have none.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function creatureIds(array $data): array
    {
        return array_values(array_unique(array_filter(
            array_column($data['combatants'], 'creatureId'),
            fn ($id) => $id !== null,
        )));
    }
}
