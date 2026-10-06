<?php

namespace Database\Seeders;

use App\Http\Requests\SaveCreatureRequest;
use App\Models\Creature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Loads the built-in SRD creatures from database/data/srd-creatures.json (made from the SRD 5.1 by
 * database/data/convert-srd-monsters.php). Safe to re-run: existing SRD creatures are updated in
 * place (matched by name) so their ids stay stable for encounters that reference them.
 */
class SrdCreatureSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('data/srd-creatures.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($data['creatures'] as $creature) {
            // The file uses {"STR": 10} for readability; the table stores an ordered [{label, value}] list.
            $creature['stats'] = collect($creature['stats'])
                ->map(fn (int $value, string $label) => ['label' => $label, 'value' => $value])
                ->values()
                ->all();
            $creature['resources'] ??= [];

            // Held to the same rules as a creature made in the app, and stored in the same shape.
            $validator = Validator::make($creature, SaveCreatureRequest::fields($creature), SaveCreatureRequest::fieldMessages());
            if ($validator->fails()) {
                throw new RuntimeException("SRD creature \"{$creature['name']}\" isn't valid: ".$validator->errors()->first());
            }

            Creature::query()->whereNull('user_id')->updateOrCreate(
                ['name' => $creature['name']],
                SaveCreatureRequest::attributesFrom($validator->validated()),
            );
        }
    }
}
