<?php

/*
|--------------------------------------------------------------------------
| Plans and their limits
|--------------------------------------------------------------------------
|
| Every account is on one of these plans (users.plan). Change the numbers
| here to change what people can make; nothing else needs touching. Switch
| an account's plan with:  php artisan plan:set someone@example.com pro
|
| Subscriptions aren't live yet, so limits are shown without prices. If you
| cache config in production, run `php artisan config:cache` after editing.
|
*/

return [

    // New accounts start on this plan.
    'default' => 'free',

    'plans' => [

        'free' => [
            'name' => 'Free',
            'limits' => [
                // Homebrew creatures, NPCs and player characters (SRD monsters don't count).
                'creatures' => 25,
                // Saved encounters.
                'encounters' => 10,
                // Campaigns you run as the DM.
                'campaigns' => 2,
                // Players who can join each campaign you run.
                'campaign_players' => 6,
                // Other people's campaigns you've joined as a player.
                'campaigns_joined' => 3,
            ],
        ],

        // Effectively unlimited until pricing is decided; the numbers are just safety caps.
        'pro' => [
            'name' => 'Pro',
            'limits' => [
                'creatures' => 1000,
                'encounters' => 500,
                'campaigns' => 50,
                'campaign_players' => 12,
                'campaigns_joined' => 50,
            ],
        ],

    ],

];
