<?php

namespace App\Support;

use App\Http\Requests\SaveCreatureRequest;
use App\Models\Creature;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Backups of a user's own creatures and encounters, as a JSON file in our own format. Mirrored by
 * resources/js/lib/backup.ts, which builds the same file for a single fight in the tracker.
 *
 * Encounters point at creatures through the file (`ref`), never by database id, so a backup can be
 * imported into another account. Your own creatures are included in full; SRD creatures only by
 * name, and are matched to the SRD on import.
 */
class Backup
{
    public const FORMAT = 'ttrpg-tracker-backup';

    public const VERSION = 1;

    public const MAX_KILOBYTES = 5120;

    /**
     * Everything the user has made.
     *
     * @return array<string, mixed>
     */
    public static function everything(User $user): array
    {
        return self::build($user->creatures()->orderBy('name')->get(), $user->encounters()->orderBy('name')->get());
    }

    /**
     * One encounter, with the user's creatures it uses so it can be imported on its own.
     *
     * @return array<string, mixed>
     */
    public static function encounter(Encounter $encounter): array
    {
        $ids = array_filter(array_column($encounter->combatants, 'creatureId'));

        return self::build($encounter->user->creatures()->whereKey($ids)->orderBy('name')->get(), collect([$encounter]));
    }

    /**
     * @param  Collection<int, Creature>  $creatures  The user's own creatures to include in full.
     * @param  Collection<int, Encounter>  $encounters
     * @return array<string, mixed>
     */
    private static function build(Collection $creatures, Collection $encounters): array
    {
        // SRD creatures the encounters use, by name only.
        $usedIds = $encounters->flatMap(fn (Encounter $e) => array_column($e->combatants, 'creatureId'))->filter()->unique();
        $srd = Creature::whereNull('user_id')->whereKey($usedIds)->get(['id', 'name']);
        $included = $creatures->pluck('id')->merge($srd->pluck('id'))->flip();

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exportedAt' => now()->toIso8601String(),
            'creatures' => [
                ...$creatures->map(fn (Creature $c) => ['ref' => self::ref($c->id), ...collect($c->toFrontend())->except('id')->all()]),
                ...$srd->map(fn (Creature $c) => ['ref' => self::ref($c->id), 'source' => 'srd', 'name' => $c->name]),
            ],
            'encounters' => $encounters->map(fn (Encounter $e) => [
                'name' => $e->name,
                'round' => $e->round,
                'activeIndex' => $e->active_index,
                'combatants' => array_map(function (array $combatant) use ($included) {
                    $id = $combatant['creatureId'];
                    unset($combatant['creatureId']);

                    // A creature that's gone (or someone else's) leaves the combatant as if quick-added.
                    return ['creature' => $id !== null && $included->has($id) ? self::ref($id) : null, ...$combatant];
                }, $e->combatants),
                'log' => $e->log ?? [],
            ])->values()->all(),
        ];
    }

    private static function ref(int $id): string
    {
        return "c{$id}";
    }

    /**
     * Adds a backup's creatures and encounters to the user's account, all or nothing.
     *
     * Creatures the user already has (same everything) are reused rather than duplicated, so
     * importing the same file twice doesn't fill the compendium with copies. Encounters are always
     * added: the user may want both versions.
     *
     * @param  array<string, mixed>  $data  The decoded file.
     * @return array{creatures: int, reused: int, encounters: int, missing: int}
     *
     * @throws ValidationException
     */
    public static function import(User $user, array $data): array
    {
        $file = Validator::make($data, [
            'format' => ['required', Rule::in([self::FORMAT])],
            'version' => ['required', 'integer', 'min:1', 'max:'.self::VERSION],
            'creatures' => ['present', 'array', 'max:2000'],
            'creatures.*.ref' => ['required', 'string', 'max:50', 'distinct'],
            'creatures.*.source' => ['required', Rule::in(['homebrew', 'srd'])],
            'creatures.*.name' => ['required', 'string', 'max:100'],
            'encounters' => ['present', 'array', 'max:1000'],
            'encounters.*.combatants' => ['present', 'array'],
            'encounters.*.combatants.*.creature' => ['present', 'nullable', 'string', 'max:50'],
        ], [
            'format.*' => "This isn't a backup from this app.",
            'version.max' => 'This backup is from a newer version of the app.',
        ]);
        if ($file->fails()) {
            throw ValidationException::withMessages(['backup' => $file->errors()->first()]);
        }

        // Work out every creature first: which are new, which the user already has, which are SRD.
        $refs = [];
        $toCreate = [];
        $reused = 0;
        // Through the same normalisation as the file's creatures, so the two compare like for like.
        $own = $user->creatures()->get()->map(fn (Creature $c) => [$c->id, SaveCreatureRequest::attributesFrom($c->toFrontend())]);
        $srd = Creature::whereNull('user_id')->pluck('id', 'name');

        foreach ($data['creatures'] as $index => $creature) {
            if ($creature['source'] === 'srd') {
                $refs[$creature['ref']] = $srd[$creature['name']] ?? null;

                continue;
            }

            $validator = Validator::make($creature, SaveCreatureRequest::fields(), SaveCreatureRequest::fieldMessages());
            if ($validator->fails()) {
                throw ValidationException::withMessages([
                    'backup' => "Creature \"{$creature['name']}\" in this backup isn't valid: ".$validator->errors()->first(),
                ]);
            }

            $attributes = SaveCreatureRequest::attributesFrom($validator->validated());
            // Loose comparison: the same fields and values, whatever order jsonb kept the keys in.
            $match = $own->first(fn (array $existing) => $existing[1] == $attributes);
            if ($match) {
                $refs[$creature['ref']] = $match[0];
                $reused++;
            } else {
                $toCreate[$creature['ref']] = $attributes;
            }
        }

        Limits::ensureRoomFor($user, 'creatures', count($toCreate));
        Limits::ensureRoomFor($user, 'encounters', count($data['encounters']));

        return DB::transaction(function () use ($user, $data, $refs, $toCreate, $reused) {
            foreach ($toCreate as $ref => $attributes) {
                $refs[$ref] = $user->creatures()->create($attributes)->id;
            }

            $missing = 0;
            foreach ($data['encounters'] as $index => $encounter) {
                $encounter['combatants'] = array_map(function (array $combatant) use ($refs, &$missing) {
                    $ref = $combatant['creature'];
                    unset($combatant['creature']);
                    $combatant['creatureId'] = $ref !== null ? ($refs[$ref] ?? null) : null;
                    if ($ref !== null && $combatant['creatureId'] === null) {
                        $missing++;
                    }

                    return $combatant;
                }, $encounter['combatants']);

                $validator = Validator::make($encounter, collect(EncounterPayload::rules())->except('campaignId')->all());
                $problems = $validator->fails() ? [] : EncounterPayload::problems($validator->validated(), $user);
                if ($validator->fails() || $problems) {
                    $name = is_string($encounter['name'] ?? null) ? $encounter['name'] : 'number '.($index + 1);

                    throw ValidationException::withMessages([
                        'backup' => "Encounter \"{$name}\" in this backup isn't valid: ".($problems ? reset($problems) : $validator->errors()->first()),
                    ]);
                }

                $user->encounters()->create(EncounterPayload::toAttributes($validator->validated(), $user));
            }

            return ['creatures' => count($toCreate), 'reused' => $reused, 'encounters' => count($data['encounters']), 'missing' => $missing];
        });
    }
}
