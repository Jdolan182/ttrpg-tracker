<?php

/**
 * Turns the SRD 5.1 monster list (database/data/source/srd-5.1-monsters.json) into the app's own
 * format in database/data/srd-creatures.json, which SrdCreatureSeeder loads. Run it again after
 * changing the mapping below:
 *
 *     sail php database/data/convert-srd-monsters.php
 *
 * D&D wording becomes the app's generic limits: "Recharge 5-6" a recharge roll, "3/Day" uses per
 * day, legendary actions a "Legendary actions" resource they cost from, and spellcasters' slots one
 * resource per spell level. Creatures already in srd-creatures.json that the source doesn't have
 * (Goblin Boss, from SRD 5.2) are kept as they are.
 */
$dir = __DIR__;
$source = json_decode(file_get_contents("{$dir}/source/srd-5.1-monsters.json"), true, flags: JSON_THROW_ON_ERROR);
$current = json_decode(file_get_contents("{$dir}/srd-creatures.json"), true, flags: JSON_THROW_ON_ERROR);

const LEGENDARY = 'Legendary actions';
// Every SRD 5.1 creature with legendary actions can take three a round.
const LEGENDARY_PER_ROUND = 3;

$skills = [
    'acrobatics' => 'Acrobatics', 'arcana' => 'Arcana', 'athletics' => 'Athletics', 'deception' => 'Deception',
    'history' => 'History', 'insight' => 'Insight', 'intimidation' => 'Intimidation', 'investigation' => 'Investigation',
    'medicine' => 'Medicine', 'nature' => 'Nature', 'perception' => 'Perception', 'performance' => 'Performance',
    'persuasion' => 'Persuasion', 'religion' => 'Religion', 'stealth' => 'Stealth', 'survival' => 'Survival',
];
$abilities = ['strength' => 'STR', 'dexterity' => 'DEX', 'constitution' => 'CON', 'intelligence' => 'INT', 'wisdom' => 'WIS', 'charisma' => 'CHA'];

$signed = fn (int $n) => ($n < 0 ? '−' : '+').abs($n);

/**
 * Takes a limit written into a name ("Fire Breath (Recharge 5-6)") out of it, returning the plain
 * name and the limit's fields. Other notes in brackets, like "(Hybrid Form Only)", stay in the name.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function splitLimit(string $name): array
{
    $limit = [];
    $plain = preg_replace_callback('/\s*\(([^)]*)\)/', function (array $match) use (&$limit) {
        $note = strtolower(trim($match[1]));
        if (preg_match('/^recharge (\d)(?:\s*[-–]\s*(\d))?$/', $note, $m)) {
            $limit = ['recharge' => ['die' => 6, 'min' => (int) $m[1]]];
        } elseif (preg_match('/^recharges after a short or long rest$/', $note)) {
            // A rest comes between fights, so in the tracker it's once per encounter.
            $limit = ['uses' => 1, 'per' => 'encounter'];
        } elseif (preg_match('/^(\d+)\/(day|turn)( each)?$/', $note, $m)) {
            $limit = ['uses' => (int) $m[1], 'per' => $m[2]];
        } elseif (preg_match('/^costs (\d+) actions$/', $note, $m)) {
            $limit = ['resource' => LEGENDARY, 'cost' => (int) $m[1]];
        } else {
            return $match[0];
        }

        return '';
    }, $name);

    return [trim($plain), $limit];
}

$entry = fn (array $a) => ['name' => trim($a['name']), 'description' => trim($a['desc'])];

$converted = [];
foreach ($source as $monster) {
    if (! isset($monster['name'])) {
        continue; // the licence text at the end of the file
    }

    // The game details a stat block lists before its traits, kept as plain traits.
    $traits = [];
    $saves = [];
    foreach ($abilities as $ability => $short) {
        if (isset($monster["{$ability}_save"])) {
            $saves[] = ucfirst(strtolower($short)).' '.$signed($monster["{$ability}_save"]);
        }
    }
    $skillList = [];
    foreach ($skills as $key => $label) {
        if (isset($monster[$key])) {
            $skillList[] = "{$label} ".$signed($monster[$key]);
        }
    }
    foreach ([
        'Saving Throws' => implode(', ', $saves),
        'Skills' => implode(', ', $skillList),
        'Damage Vulnerabilities' => $monster['damage_vulnerabilities'] ?? '',
        'Damage Resistances' => $monster['damage_resistances'] ?? '',
        'Damage Immunities' => $monster['damage_immunities'] ?? '',
        'Condition Immunities' => $monster['condition_immunities'] ?? '',
        'Senses' => $monster['senses'] ?? '',
        'Languages' => $monster['languages'] ?? '',
    ] as $name => $text) {
        if (trim($text) !== '') {
            $traits[] = ['name' => $name, 'description' => trim($text)];
        }
    }

    // Only actions can track a limit, so a limited trait (Legendary Resistance (3/Day)) becomes one.
    $actions = [];
    foreach ($monster['special_abilities'] ?? [] as $ability) {
        [$name, $limit] = splitLimit($ability['name']);
        if ($limit) {
            $actions[] = [...$entry($ability), 'name' => $name, ...$limit];
        } else {
            $traits[] = $entry($ability);
        }
    }
    foreach ($monster['actions'] ?? [] as $action) {
        [$name, $limit] = splitLimit($action['name']);
        $actions[] = [...$entry($action), 'name' => $name, ...$limit];
    }
    foreach ($monster['reactions'] ?? [] as $reaction) {
        $actions[] = [...$entry($reaction), 'name' => trim($reaction['name']).' (Reaction)'];
    }
    foreach ($monster['legendary_actions'] ?? [] as $legendary) {
        [$name, $limit] = splitLimit($legendary['name']);
        $actions[] = [...$entry($legendary), 'name' => $name, ...($limit ?: ['resource' => LEGENDARY, 'cost' => 1])];
    }

    // Names must be unique: drop exact repeats (a couple of source entries list an action twice),
    // and tell apart different ones that share a name.
    $unique = [];
    foreach ($actions as $action) {
        $key = strtolower($action['name']);
        if (isset($unique[$key])) {
            if ($unique[$key] == $action) {
                continue;
            }
            $action['name'] .= isset($action['resource']) ? ' (Legendary)' : ' (2)';
            $key = strtolower($action['name']);
        }
        $unique[$key] = $action;
    }

    $resources = [];
    if ($monster['legendary_actions'] ?? []) {
        $resources[] = ['name' => LEGENDARY, 'max' => LEGENDARY_PER_ROUND, 'per' => 'turn'];
    }
    // "1st level (4 slots)": one pool per spell level, used by hand from the tracker.
    $spellcasting = implode("\n", array_map(fn ($a) => $a['desc'], $monster['special_abilities'] ?? []));
    preg_match_all('/(\d)(?:st|nd|rd|th) level \((\d+) slots?\)/i', $spellcasting, $slots, PREG_SET_ORDER);
    foreach ($slots as [, $level, $count]) {
        $resources[] = ['name' => "Level {$level} spell slots", 'max' => (int) $count, 'per' => 'day'];
    }

    $subtype = trim($monster['subtype'] ?? '');
    $converted[$monster['name']] = [
        'name' => $monster['name'],
        // The SRD's generic people (Bandit, Guard, Mage…) are the ones of "any race".
        'kind' => str_contains($subtype, 'any race') ? 'npc' : 'monster',
        'summary' => "{$monster['size']} {$monster['type']}".($subtype !== '' ? " ({$subtype})" : '').", {$monster['alignment']}",
        'rating' => "CR {$monster['challenge_rating']}",
        'hp' => $monster['hit_points'],
        'ac' => $monster['armor_class'],
        'speed' => $monster['speed'],
        'stats' => array_combine(array_values($abilities), array_map(fn ($ability) => $monster[$ability], array_keys($abilities))),
        'traits' => $traits,
        'actions' => array_values($unique),
        'resources' => $resources,
    ];
}

foreach ($current['creatures'] as $creature) {
    $converted[$creature['name']] ??= $creature;
}
ksort($converted, SORT_NATURAL | SORT_FLAG_CASE);

$current['creatures'] = array_values($converted);
file_put_contents("{$dir}/srd-creatures.json", json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

echo count($current['creatures'])." creatures written to database/data/srd-creatures.json\n";
