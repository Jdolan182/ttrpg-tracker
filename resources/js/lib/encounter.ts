import type { Combatant, CombatantSide, Creature } from '@/types/tracker';
import type { LucideIcon } from 'lucide-vue-next';
import {
    ArrowDownToLine,
    Ban,
    EarOff,
    EyeOff,
    FlaskConical,
    Frown,
    Ghost,
    Grab,
    Heart,
    Link,
    Moon,
    Mountain,
    Sparkles,
    Zap,
} from 'lucide-vue-next';

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
 * Fills in a side for combatants saved before sides existed, and keeps players (and only players)
 * on the player side. Updates the array in place and returns it.
 */
export const normalizeSides = (combatants: Combatant[], creaturesById: Map<number, Creature>): Combatant[] => {
    for (const combatant of combatants) {
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

export const byInitiative = (a: Combatant, b: Combatant) => b.initiative - a.initiative;

const newId = () => (typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);

/**
 * Combatants for `count` copies of a creature. They get numbered ("Goblin 1", "Goblin 2")
 * when there's more than one in the fight, continuing from any already there.
 */
export const combatantsFor = (
    creature: Creature,
    count: number,
    initiative: number,
    existing: Combatant[],
    side: CombatantSide = defaultSide(creature),
): Combatant[] => {
    const sameCreature = existing.filter((c) => c.creatureId === creature.id).length;
    const numbered = sameCreature + count > 1;

    return Array.from({ length: count }, (_, i) => ({
        id: newId(),
        creatureId: creature.id,
        name: numbered ? `${creature.name} ${sameCreature + i + 1}` : creature.name,
        side: creature.kind === 'player' ? 'player' : side === 'player' ? 'enemy' : side,
        initiative,
        hp: creature.hp,
        maxHp: creature.hp,
        ac: creature.ac,
        conditions: [],
    }));
};
