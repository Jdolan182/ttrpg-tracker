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
    creatureId: number;
    name: string;
    side: CombatantSide;
    initiative: number;
    hp: number;
    maxHp: number;
    ac: number;
    conditions: string[];
    // Times each limited action has been used, keyed by action name.
    used: Record<string, number>;
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
