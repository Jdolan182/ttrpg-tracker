import type { Combatant, Creature } from '@/types/tracker';

// 5e's list for now; this moves onto the game system once systems are configurable.
export const conditions = [
    'Blinded',
    'Charmed',
    'Deafened',
    'Frightened',
    'Grappled',
    'Incapacitated',
    'Invisible',
    'Paralyzed',
    'Poisoned',
    'Prone',
    'Restrained',
    'Stunned',
    'Unconscious',
];

export const byInitiative = (a: Combatant, b: Combatant) => b.initiative - a.initiative;

const newId = () => (typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);

/**
 * Combatants for `count` copies of a creature. Monsters get numbered ("Goblin 1", "Goblin 2")
 * when there's more than one in the fight, continuing from any already there.
 */
export const combatantsFor = (creature: Creature, count: number, initiative: number, existing: Combatant[]): Combatant[] => {
    const sameCreature = existing.filter((c) => c.creatureId === creature.id).length;
    const numbered = sameCreature + count > 1;

    return Array.from({ length: count }, (_, i) => ({
        id: newId(),
        creatureId: creature.id,
        name: numbered ? `${creature.name} ${sameCreature + i + 1}` : creature.name,
        initiative,
        hp: creature.hp,
        maxHp: creature.hp,
        ac: creature.ac,
        conditions: [],
    }));
};
