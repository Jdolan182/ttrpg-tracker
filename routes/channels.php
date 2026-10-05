<?php

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// The DM and the campaign's players hear when its fight changes.
Broadcast::channel('campaign.{campaign}', fn (User $user, Campaign $campaign) => $user->can('view', $campaign));
