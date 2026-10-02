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

// A saved encounter in the tracker's list; the full thing is loaded when it's opened.
export interface EncounterSummary {
    id: number;
    name: string;
}

// Mirrors App\Models\Encounter::toFrontend().
export interface Encounter {
    id: number;
    name: string;
    // 0 while setting up, before combat starts.
    round: number;
    activeIndex: number;
    combatants: Combatant[];
    log: LogEntry[];
}
