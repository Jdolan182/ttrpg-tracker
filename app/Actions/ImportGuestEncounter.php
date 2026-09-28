<?php

namespace App\Actions;

use App\Models\Encounter;
use App\Models\User;
use App\Support\EncounterPayload;
use Illuminate\Support\Facades\Validator;

/**
 * Copies the fight a guest was running (from their browser's localStorage) into their new account.
 */
class ImportGuestEncounter
{
    /**
     * Returns null when there's nothing usable to import. Bad guest data must never block a sign-up,
     * so invalid payloads are skipped rather than reported as validation errors.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function handle(User $user, ?array $payload): ?Encounter
    {
        if (! $payload) {
            return null;
        }

        $validator = Validator::make($payload, EncounterPayload::rules(minCombatants: 1));
        if ($validator->fails()) {
            return null;
        }

        $data = $validator->validated();
        if (EncounterPayload::problems($data, $user)) {
            return null;
        }

        return $user->encounters()->create(EncounterPayload::toAttributes($data, $user));
    }
}
