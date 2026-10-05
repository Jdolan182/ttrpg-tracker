import type { LogEntry } from '@/lib/combatLog';

export type CreatureKind = 'monster' | 'npc' | 'player';

export type CreatureSource = 'srd' | 'homebrew';

export interface CreatureStat {
    label: string;
    value: number;
}

export interface CreatureEntry {
    name: string;
    description: string;
}

// When a limited action's uses come back. Mirrors Creature::LIMIT_PERIODS.
export type LimitPeriod = 'turn' | 'round' | 'encounter' | 'day';

export interface CreatureAction extends CreatureEntry {
    // Null when the action can be used any number of times.
    uses: number | null;
    per: LimitPeriod | null;
}

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
}

export type CombatantSide = 'player' | 'ally' | 'neutral' | 'enemy';

export interface Combatant {
    // Unique within the encounter; the same creature can appear several times.
    id: string;
    // The compendium creature it came from; null when quick-added straight into the encounter
    // (e.g. a guest's player character), which means there's no stat block or actions.
    creatureId: number | null;
    name: string;
    side: CombatantSide;
    initiative: number;
    hp: number;
    maxHp: number;
    ac: number;
    conditions: string[];
    // Times each limited action has been used, keyed by action name.
    used: Record<string, number>;
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
}

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
    initiative: number;
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
