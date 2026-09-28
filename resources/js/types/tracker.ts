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
    actions: CreatureEntry[];
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
}

// Mirrors App\Models\Encounter::toFrontend().
export interface Encounter {
    id: number;
    name: string;
    round: number;
    activeIndex: number;
    combatants: Combatant[];
}
