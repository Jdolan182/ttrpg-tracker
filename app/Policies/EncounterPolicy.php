<?php

namespace App\Policies;

use App\Models\Encounter;
use App\Models\User;

class EncounterPolicy
{
    public function update(User $user, Encounter $encounter): bool
    {
        return $encounter->user_id === $user->id;
    }

    public function delete(User $user, Encounter $encounter): bool
    {
        return $encounter->user_id === $user->id;
    }
}
