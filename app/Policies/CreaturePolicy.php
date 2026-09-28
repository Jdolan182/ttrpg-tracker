<?php

namespace App\Policies;

use App\Models\Creature;
use App\Models\User;

class CreaturePolicy
{
    /**
     * SRD creatures (no owner) are read-only; homebrew belongs to whoever made it.
     */
    public function update(User $user, Creature $creature): bool
    {
        return $creature->user_id === $user->id;
    }

    public function delete(User $user, Creature $creature): bool
    {
        return $creature->user_id === $user->id;
    }
}
