import type { Combatant, Creature } from '@/types/tracker';
import { describe, expect, it } from 'vitest';
import { BACKUP_FORMAT, BACKUP_VERSION, fightBackup } from './backup';

const creature = (id: number, source: Creature['source'], name: string): Creature => ({
    id,
    kind: 'monster',
    source,
    name,
    summary: 'Small humanoid',
    rating: 'CR 1/4',
    hp: 7,
    ac: 15,
    initiativeBonus: null,
    speed: '30 ft.',
    stats: [{ label: 'DEX', value: 14 }],
    traits: [],
    actions: [],
    resources: [],
});

const combatant = (name: string, creatureId: number | null): Combatant => ({
    id: name,
    creatureId,
    name,
    side: 'enemy',
    initiative: 10,
    hp: 7,
    maxHp: 7,
    ac: 15,
    conditions: [],
    used: {},
});

// The server's format (App\Support\Backup), so a file from the tracker imports like any other.
describe('fightBackup', () => {
    const creatures = new Map([
        [3, creature(3, 'homebrew', 'Bog Ogre')],
        [9, creature(9, 'srd', 'Goblin')],
    ]);
    const backup = fightBackup(
        {
            name: 'Ambush',
            round: 1,
            activeIndex: 0,
            combatants: [
                combatant('Bog Ogre', 3),
                combatant('Goblin 1', 9),
                combatant('Goblin 2', 9),
                combatant('Aria', null),
                combatant('Ghost', 99),
            ],
            log: [],
        },
        creatures,
    );

    it('is marked as our format', () => {
        expect(backup).toMatchObject({ format: BACKUP_FORMAT, version: BACKUP_VERSION });
    });

    it('includes homebrew in full and SRD creatures by name, once each, without database ids', () => {
        expect(backup.creatures).toHaveLength(2);
        expect(backup.creatures.find((c) => c.name === 'Bog Ogre')).toMatchObject({ ref: 'c3', source: 'homebrew', hp: 7 });
        expect(backup.creatures.find((c) => c.name === 'Goblin')).toEqual({ ref: 'c9', source: 'srd', name: 'Goblin' });
        expect(backup.creatures.every((c) => !('id' in c))).toBe(true);
    });

    it('points combatants at creatures by ref, and drops creatures that are gone', () => {
        const combatants = backup.encounters[0].combatants;
        expect(combatants.map((c) => c.creature)).toEqual(['c3', 'c9', 'c9', null, null]);
        expect(combatants.every((c) => !('creatureId' in c))).toBe(true);
    });
});
