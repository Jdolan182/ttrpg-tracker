import { modifier } from '@/lib/stats';
import type { Combatant, CombatantSide, Creature, CreatureAction, LimitPeriod } from '@/types/tracker';
import type { LucideIcon } from 'lucide-vue-next';
import { ArrowDownToLine, Ban, EarOff, EyeOff, FlaskConical, Frown, Ghost, Grab, Heart, Link, Moon, Mountain, Sparkles, Zap } from 'lucide-vue-next';

// 5e's conditions for now; these move onto the game system once systems are configurable.
// Combatants store the name, so the icon is purely presentation and can change freely.
export const conditions: { name: string; icon: LucideIcon }[] = [
    { name: 'Blinded', icon: EyeOff },
    { name: 'Charmed', icon: Heart },
    { name: 'Deafened', icon: EarOff },
    { name: 'Frightened', icon: Frown },
    { name: 'Grappled', icon: Grab },
    { name: 'Incapacitated', icon: Ban },
    { name: 'Invisible', icon: Ghost },
    { name: 'Paralyzed', icon: Zap },
    { name: 'Petrified', icon: Mountain },
    { name: 'Poisoned', icon: FlaskConical },
    { name: 'Prone', icon: ArrowDownToLine },
    { name: 'Restrained', icon: Link },
    { name: 'Stunned', icon: Sparkles },
    { name: 'Unconscious', icon: Moon },
];

const conditionIcons = new Map(conditions.map((c) => [c.name, c.icon]));

export const conditionIcon = (name: string): LucideIcon | undefined => conditionIcons.get(name);

// Which side of the fight a combatant is on. Players are always "player"; everyone else can switch.
export const sides: { value: CombatantSide; label: string; dot: string; text: string }[] = [
    { value: 'player', label: 'Player', dot: 'bg-sky-500', text: 'text-sky-700 dark:text-sky-400' },
    { value: 'ally', label: 'Ally', dot: 'bg-emerald-500', text: 'text-emerald-700 dark:text-emerald-400' },
    { value: 'neutral', label: 'Neutral', dot: 'bg-neutral-400', text: 'text-muted-foreground' },
    { value: 'enemy', label: 'Enemy', dot: 'bg-red-500', text: 'text-red-700 dark:text-red-400' },
];

export const switchableSides = sides.filter((s) => s.value !== 'player');

export const sideInfo = (side: CombatantSide) => sides.find((s) => s.value === side) ?? sides[3];

/** Where a creature starts: players on the player side, monsters as enemies, NPCs as neutral. */
export const defaultSide = (creature: Pick<Creature, 'kind'> | undefined): CombatantSide => {
    if (creature?.kind === 'player') return 'player';
    if (creature?.kind === 'npc') return 'neutral';
    return 'enemy';
};

/**
 * Brings combatants from older saves up to date: fills in a side (keeping players, and only players,
 * on the player side) and an empty use count. Updates the array in place and returns it.
 */
export const normalizeCombatants = (combatants: Combatant[], creaturesById: Map<number, Creature>): Combatant[] => {
    for (const combatant of combatants) {
        // An empty object round-trips through PHP as [], and older saves have none at all.
        if (!combatant.used || Array.isArray(combatant.used)) combatant.used = {};

        const creature = creaturesById.get(combatant.creatureId);
        // A deleted creature's kind is unknown, so trust whatever side was saved.
        if (!creature && combatant.side) continue;

        const isPlayer = creature?.kind === 'player';
        if (!combatant.side || isPlayer !== (combatant.side === 'player')) {
            combatant.side = defaultSide(creature);
        }
    }
    return combatants;
};

// What using an action does to its targets.
export type ActionEffect = 'none' | 'damage' | 'heal';

// Limited-use actions.
export const limitPeriods: { value: LimitPeriod; label: string; short: string }[] = [
    { value: 'turn', label: 'per turn', short: 'Turn' },
    { value: 'round', label: 'per round', short: 'Round' },
    { value: 'encounter', label: 'per encounter', short: 'Encounter' },
    { value: 'day', label: 'per day', short: 'Day' },
];

/** SRD-style label for a limit, e.g. "3/Day". Empty for unlimited actions. */
export const limitLabel = (action: CreatureAction) =>
    action.uses ? `${action.uses}/${limitPeriods.find((p) => p.value === action.per)?.short ?? 'Day'}` : '';

/** Uses left for a limited action, or null when it's unlimited. */
export const usesLeft = (combatant: Combatant, action: CreatureAction): number | null =>
    action.uses ? Math.max(0, action.uses - (combatant.used[action.name] ?? 0)) : null;

/** Gives back the uses of any of the creature's actions that reset on one of `periods`. */
export const restoreUses = (combatant: Combatant, creature: Creature | undefined, periods: LimitPeriod[]) => {
    for (const action of creature?.actions ?? []) {
        if (action.per && periods.includes(action.per)) delete combatant.used[action.name];
    }
};

export const byInitiative = (a: Combatant, b: Combatant) => b.initiative - a.initiative;

/**
 * Adds combatants without disturbing a hand-arranged order: each one goes after everyone
 * with the same or higher initiative, in front of the first lower one. Updates `list` in place.
 */
export const insertByInitiative = (list: Combatant[], added: Combatant[]) => {
    for (const combatant of added) {
        const index = list.findIndex((c) => c.initiative < combatant.initiative);
        list.splice(index === -1 ? list.length : index, 0, combatant);
    }
};

/** A fair roll of one die with `sides` faces. */
export const rollDie = (sides: number) => {
    const values = new Uint32Array(1);
    crypto.getRandomValues(values);
    return (values[0] % sides) + 1;
};

// Uses the d20 convention: a stat called DEX or Dexterity gives the bonus. Anything else rolls a plain d20.
// This moves onto the game system once systems are configurable.
export const initiativeBonus = (creature: Creature | undefined) => {
    const dex = creature?.stats.find((s) => ['dex', 'dexterity'].includes(s.label.trim().toLowerCase()));
    return dex ? modifier(dex.value) : 0;
};

export const rollInitiative = (creature: Creature | undefined) => rollDie(20) + initiativeBonus(creature);

const newId = () => (typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);

/**
 * Combatants for `count` copies of a creature. They get numbered ("Goblin 1", "Goblin 2")
 * when there's more than one in the fight, continuing from any already there.
 * A null initiative means "roll it": each monster or NPC rolls separately, and players get 0
 * because players roll their own.
 */
export const combatantsFor = (
    creature: Creature,
    count: number,
    initiative: number | null,
    existing: Combatant[],
    side: CombatantSide = defaultSide(creature),
): Combatant[] => {
    const sameCreature = existing.filter((c) => c.creatureId === creature.id).length;
    const numbered = sameCreature + count > 1;
    const isPlayer = creature.kind === 'player';

    return Array.from({ length: count }, (_, i) => ({
        id: newId(),
        creatureId: creature.id,
        name: numbered ? `${creature.name} ${sameCreature + i + 1}` : creature.name,
        side: isPlayer ? 'player' : side === 'player' ? 'enemy' : side,
        initiative: initiative ?? (isPlayer ? 0 : rollInitiative(creature)),
        hp: creature.hp,
        maxHp: creature.hp,
        ac: creature.ac,
        conditions: [],
        used: {},
    }));
};
