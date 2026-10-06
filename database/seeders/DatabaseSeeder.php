<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SrdCreatureSeeder::class);

        // The test login's password is "password", so it must never exist on a real server.
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('Skipped the test account: it is only created locally.');

            return;
        }

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
