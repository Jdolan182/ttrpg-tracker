<?php

namespace Database\Seeders;

use App\Models\Creature;
use Illuminate\Database\Seeder;

/**
 * Loads the built-in SRD creatures from database/data/srd-creatures.json. Safe to re-run:
 * existing SRD creatures are updated in place (matched by name) so their ids stay stable
 * for encounters that reference them.
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

            Creature::query()->whereNull('user_id')->updateOrCreate(['name' => $creature['name']], $creature);
        }
    }
}
