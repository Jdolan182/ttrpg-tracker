import type { LogEntry } from '@/lib/combatLog';

export type CreatureKind = 'monster' | 'npc' | 'player';

export type CreatureSource = 'srd' | 'homebrew';

export type CreatureStat = {
    label: string;
    value: number;
};

export type CreatureEntry = {
    name: string;
    description: string;
};

// When a limited action's uses come back. Mirrors Creature::LIMIT_PERIODS.
export type LimitPeriod = 'turn' | 'round' | 'encounter' | 'day';

// A recharge roll: at the start of its owner's turn, a spent action comes back on `min` or more on a `die`-sided die.
export type Recharge = { die: number; min: number };

// An action has at most one kind of limit (see limitKind()); all null means unlimited.
export type CreatureAction = CreatureEntry & {
    // A number of uses that come back each turn, round, encounter or day.
    uses: number | null;
    per: LimitPeriod | null;
    // Or: once used, it comes back on a roll.
    recharge: Recharge | null;
    // Or: once used, it's unavailable for this many rounds: a number, or dice rolled on use ("1d4").
    cooldown: string | null;
    // Or: each use spends `cost` from one of the creature's resources, named here.
    resource: string | null;
    cost: number | null;
};

// A named pool its actions spend from: legendary actions, spell slots, mana, focus points…
// Mirrors the resources rules in SaveCreatureRequest.
export type CreatureResource = {
    name: string;
    max: number;
    // When it fills back up.
    per: LimitPeriod;
};

// Mirrors App\Models\Creature::toFrontend().
export interface Creature {
    id: number;
    kind: CreatureKind;
    source: CreatureSource;
    name: string;
    // Free-text subtitle, e.g. "Small humanoid" or "Level 5 elf ranger".
    summary: string;
    // Challenge rating, level or tier: whatever the game system rates creatures by.
    rating: string;
    hp: number;
    ac: number;
    // Added to the initiative roll. Null means work it out: DEX in d20 games, otherwise nothing.
    initiativeBonus: number | null;
    speed: string;
    // Ordered so each game system can define its own stats.
    stats: CreatureStat[];
    traits: CreatureEntry[];
    actions: CreatureAction[];
    resources: CreatureResource[];
}

export type CombatantSide = 'player' | 'ally' | 'neutral' | 'enemy';

export type Combatant = {
    // Unique within the encounter; the same creature can appear several times.
    id: string;
    // The compendium creature it came from; null when quick-added straight into the encounter
    // (e.g. a guest's player character), which means there's no stat block or actions.
    creatureId: number | null;
    name: string;
    side: CombatantSide;
    // Null until it's rolled or entered (players roll their own), so a real roll of 0 still shows as 0.
    initiative: number | null;
    hp: number;
    maxHp: number;
    ac: number;
    conditions: string[];
    // Limited actions' state, keyed by action name. What the number means depends on the limit: times
    // used (uses per period), 1 while spent (recharge), or rounds left (cooldown). No entry: ready.
    used: Record<string, number>;
    // How much of each of the creature's resources has been spent, keyed by resource name.
    spent?: Record<string, number>;
    // Only on quick-added combatants (no creature to take stats from); optional even then.
    stats?: CreatureStat[];
    // Rounds left on timed conditions, keyed by condition name. Counted down at the end of this
    // combatant's own turn; conditions without an entry last until removed.
    durations?: Record<string, number>;
    // Soaks up damage before HP. Doesn't stack: gaining more keeps the higher amount.
    tempHp?: number;
    concentrating?: boolean;
    // Hidden from players (for the player view); the DM still sees and runs them.
    hidden?: boolean;
    // Player characters at 0 HP. Three successes: stable. Three failures: dead.
    deathSaves?: { successes: number; failures: number };
};

export type EnemyHpDisplay = 'bands' | 'exact' | 'hidden';

// A campaign the user runs, as the tracker sees it: for the campaign picker and "Add party".
export interface TrackerCampaign {
    id: number;
    name: string;
    enemyHp: EnemyHpDisplay;
    partyIds: number[];
}

// How a combatant is doing, for players. Dying, stable and dead are player characters at 0 HP.
export type HealthStatus = 'healthy' | 'bloodied' | 'down' | 'dying' | 'stable' | 'dead';

// One combatant as players see it. Mirrors App\Support\PlayerView: HP numbers and status are null
// when the campaign keeps them from players.
export interface PlayerViewCombatant {
    id: string;
    name: string;
    side: CombatantSide;
    initiative: number | null;
    active: boolean;
    hp: number | null;
    maxHp: number | null;
    tempHp: number | null;
    status: HealthStatus | null;
    conditions: { name: string; rounds: number | null }[];
    concentrating: boolean;
}

// A fight as players see it: hidden combatants already left out.
export interface PlayerViewFight {
    name: string;
    round: number;
    combatants: PlayerViewCombatant[];
    // The latest part of the history, filtered for players.
    log: LogEntry[];
    // When the DM last changed it; only on fights that come from the server.
    updatedAt?: string | null;
}

// A saved encounter in the tracker's list; the full thing is loaded when it's opened.
export interface EncounterSummary {
    id: number;
    name: string;
}

// Mirrors App\Models\Encounter::toFrontend().
export interface Encounter {
    id: number;
    name: string;
    campaignId: number | null;
    // 0 while setting up, before combat starts.
    round: number;
    activeIndex: number;
    combatants: Combatant[];
    log: LogEntry[];
}
