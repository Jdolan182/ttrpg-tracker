<?php

namespace App\Support;

/**
 * What a page tells search engines and link previews, written into the first HTML response
 * (resources/views/app.blade.php). Pages are built in the browser, and link previews (Discord,
 * WhatsApp…) never run JavaScript, so this is all they see. Titles match each page's <Head title>,
 * which the browser keeps using after that.
 */
class Seo
{
    // Pages worth finding from a search. Anything else (settings, your own campaigns and encounters,
    // invites…) is marked noindex: it's behind a login or only means something to one person.
    private const PUBLIC = [
        // "D&D" and "encounter tracker" are what people search; "any TTRPG" is what it is.
        'Encounters/Index' => [
            'title' => 'Encounter & Initiative Tracker for D&D and Any TTRPG',
            'description' => 'Free encounter and combat tracker for D&D 5e and any other tabletop RPG. Roll initiative, track HP, conditions and recharges, use 300+ SRD monsters or your own stats, and show players the fight live. No account needed.',
        ],
        'Compendium/Index' => [
            'title' => 'Compendium',
            'description' => 'Over 300 monsters from the D&D 5e SRD with full stat blocks, plus your own homebrew creatures. Add any of them to an encounter in one click.',
        ],
        'auth/Register' => [
            'title' => 'Register',
            'description' => 'Create a free account to save encounters, make your own creatures and run campaigns your players can follow live.',
        ],
        'Privacy' => [
            'title' => 'Privacy',
            'description' => 'What Turnkeeper keeps about you, and why, in plain words.',
        ],
    ];

    /**
     * @return array{title: string, description: string, index: bool}
     */
    public static function for(string $component): array
    {
        $app = config('app.name');
        $page = self::PUBLIC[$component] ?? null;

        return [
            // Same as the browser's title template in resources/js/app.ts.
            'title' => $page ? "{$page['title']} - {$app}" : $app,
            'description' => $page['description'] ?? self::PUBLIC['Encounters/Index']['description'],
            'index' => $page !== null,
        ];
    }

    /** The pages in the sitemap: the public ones that have a fixed address. */
    public static function sitemapPaths(): array
    {
        return ['/', '/compendium', '/register', '/privacy'];
    }
}
