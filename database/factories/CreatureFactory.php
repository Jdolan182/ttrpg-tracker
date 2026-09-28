<?php

namespace Database\Factories;

use App\Models\Creature;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Creature>
 */
class CreatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'monster',
            'name' => ucfirst(fake()->unique()->word()),
            'summary' => 'Medium humanoid',
            'rating' => 'CR 1',
            'hp' => fake()->numberBetween(5, 60),
            'ac' => fake()->numberBetween(10, 18),
            'speed' => '30 ft.',
            'stats' => [
                ['label' => 'STR', 'value' => 10],
                ['label' => 'DEX', 'value' => 12],
            ],
            'traits' => [],
            'actions' => [['name' => 'Slam', 'description' => 'Melee attack: +3 to hit. Hit: 4 bludgeoning damage.']],
        ];
    }

    /**
     * A built-in SRD creature that belongs to nobody.
     */
    public function srd(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
