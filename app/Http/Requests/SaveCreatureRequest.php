<?php

namespace App\Http\Requests;

use App\Models\Creature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCreatureRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::fields($this->all());
    }

    /**
     * The rules for one creature, also used when importing a backup.
     *
     * @param  array<string, mixed>  $data  The creature being checked: actions may only spend its own resources.
     * @return array<string, mixed>
     */
    public static function fields(array $data): array
    {
        $resourceNames = array_filter(array_map(
            fn ($resource) => is_array($resource) ? ($resource['name'] ?? null) : null,
            is_array($data['resources'] ?? null) ? $data['resources'] : [],
        ), 'is_string');
        // Each action has at most one kind of limit.
        $oneLimit = fn (string ...$others) => 'prohibits:'.implode(',', array_map(fn ($field) => "actions.*.{$field}", $others));

        return [
            'kind' => ['required', Rule::in(Creature::KINDS)],
            'name' => ['required', 'string', 'max:100'],
            'summary' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'string', 'max:50'],
            'hp' => ['required', 'integer', 'min:1', 'max:100000'],
            'ac' => ['required', 'integer', 'min:0', 'max:1000'],
            // Named like toFrontend(), so backups (which carry that shape) validate with these rules too.
            'initiativeBonus' => ['nullable', 'integer', 'between:-100,100'],
            'speed' => ['nullable', 'string', 'max:100'],
            'stats' => ['present', 'array', 'max:30'],
            'stats.*.label' => ['required', 'string', 'max:20', 'distinct:ignore_case'],
            'stats.*.value' => ['required', 'integer', 'between:-1000,1000'],
            'traits' => ['present', 'array', 'max:50'],
            'traits.*.name' => ['required', 'string', 'max:100'],
            'traits.*.description' => ['required', 'string', 'max:2000'],
            'actions' => ['present', 'array', 'max:50'],
            // Names identify actions when counting uses in a fight, so they must be unique.
            'actions.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'actions.*.description' => ['required', 'string', 'max:2000'],
            // Optional limit, e.g. 3 uses per day. Blank uses means unlimited.
            'actions.*.uses' => ['nullable', 'integer', 'min:1', 'max:99', $oneLimit('recharge', 'cooldown', 'resource')],
            'actions.*.per' => ['nullable', 'required_with:actions.*.uses', Rule::in(Creature::LIMIT_PERIODS)],
            // Or: once used, it comes back at the start of its turn on a roll of `min` or more on a `die`-sided die.
            'actions.*.recharge' => ['nullable', 'array', $oneLimit('cooldown', 'resource')],
            'actions.*.recharge.die' => ['required_with:actions.*.recharge', 'integer', 'between:2,100'],
            'actions.*.recharge.min' => ['required_with:actions.*.recharge', 'integer', 'min:1', 'lte:actions.*.recharge.die'],
            // Or: once used, it's unavailable for this many rounds (a number, or dice rolled on use).
            'actions.*.cooldown' => ['nullable', 'string', 'max:20', 'regex:'.Creature::COOLDOWN_PATTERN, $oneLimit('resource')],
            // Or: each use spends `cost` from one of the creature's resources.
            'actions.*.resource' => ['nullable', 'string', Rule::in($resourceNames)],
            'actions.*.cost' => ['nullable', 'required_with:actions.*.resource', 'integer', 'min:1', 'max:99'],
            // Named pools that refill each turn/round/encounter/day: legendary actions, spell slots, mana…
            'resources' => ['nullable', 'array', 'max:20'],
            'resources.*.name' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'resources.*.max' => ['required', 'integer', 'min:1', 'max:99'],
            'resources.*.per' => ['required', Rule::in(Creature::LIMIT_PERIODS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::fieldMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function fieldMessages(): array
    {
        return [
            'stats.*.label.distinct' => 'Each stat needs a different name.',
            'actions.*.name.distinct' => 'Each action needs a different name.',
            'actions.*.per.required_with' => 'Choose how often the uses reset.',
            'actions.*.uses.prohibits' => 'Choose one kind of limit for each action.',
            'actions.*.recharge.prohibits' => 'Choose one kind of limit for each action.',
            'actions.*.cooldown.prohibits' => 'Choose one kind of limit for each action.',
            'actions.*.recharge.min.lte' => "The recharge number can't be higher than the die.",
            'actions.*.cooldown.regex' => 'Write the cooldown as a number of rounds or as dice, like 2 or 1d4.',
            'actions.*.resource.in' => "Choose one of this creature's resources.",
            'actions.*.cost.required_with' => 'Say how much of the resource each use costs.',
            'resources.*.name.distinct' => 'Each resource needs a different name.',
        ];
    }

    /**
     * Model attributes with only the known keys of each list entry.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return self::attributesFrom($this->validated());
    }

    /**
     * @param  array<string, mixed>  $data  Already passed fields().
     * @return array<string, mixed>
     */
    public static function attributesFrom(array $data): array
    {
        $entries = fn (array $list) => array_map(
            fn (array $entry) => ['name' => $entry['name'], 'description' => $entry['description']],
            $list,
        );

        return [
            'kind' => $data['kind'],
            'name' => $data['name'],
            'summary' => $data['summary'] ?? '',
            'rating' => $data['rating'] ?? '',
            'hp' => (int) $data['hp'],
            'ac' => (int) $data['ac'],
            'initiative_bonus' => isset($data['initiativeBonus']) ? (int) $data['initiativeBonus'] : null,
            'speed' => $data['speed'] ?? '',
            // Form inputs send numbers as strings; store real integers in the JSON.
            'stats' => array_map(fn (array $stat) => ['label' => $stat['label'], 'value' => (int) $stat['value']], $data['stats']),
            'traits' => $entries($data['traits']),
            'actions' => array_map(fn (array $action) => [
                'name' => $action['name'],
                'description' => $action['description'],
                'uses' => isset($action['uses']) ? (int) $action['uses'] : null,
                'per' => isset($action['uses']) ? $action['per'] : null,
                'recharge' => isset($action['recharge']) ? ['die' => (int) $action['recharge']['die'], 'min' => (int) $action['recharge']['min']] : null,
                'cooldown' => isset($action['cooldown']) ? strtolower($action['cooldown']) : null,
                'resource' => $action['resource'] ?? null,
                'cost' => isset($action['resource']) ? (int) $action['cost'] : null,
            ], $data['actions']),
            'resources' => array_map(fn (array $resource) => [
                'name' => $resource['name'],
                'max' => (int) $resource['max'],
                'per' => $resource['per'],
            ], $data['resources'] ?? []),
        ];
    }
}
