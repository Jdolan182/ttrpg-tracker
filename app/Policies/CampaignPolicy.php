<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    /** The DM and the campaign's players can see it. */
    public function view(User $user, Campaign $campaign): bool
    {
        return $campaign->isRunBy($user) || $campaign->hasPlayer($user);
    }

    /** Only the DM changes it: details, party, encounters, players and the invite link. */
    public function update(User $user, Campaign $campaign): bool
    {
        return $campaign->isRunBy($user);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $campaign->isRunBy($user);
    }

    /** Players pick their own character; the DM doesn't claim on their behalf. */
    public function claim(User $user, Campaign $campaign): bool
    {
        return $campaign->hasPlayer($user);
    }
}
