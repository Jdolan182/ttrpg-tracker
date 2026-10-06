import type { LogEntry } from '@/lib/combatLog';
import { MAX_COMBATANTS } from '@/lib/encounter';
import type { Combatant, Creature } from '@/types/tracker';
import { describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { useCombat } from './useCombat';
import { useUndo } from './useUndo';

const creature: Creature = {
    id: 1,
    kind: 'monster',
    source: 'homebrew',
    name: 'Ogre',
    summary: '',
    rating: '',
    hp: 30,
    ac: 11,
    initiativeBonus: 0,
    speed: '',
    stats: [],
    traits: [],
    actions: [
        { name: 'Slam', description: '', uses: 1, per: 'encounter' },
        { name: 'Roar', description: '', uses: 1, per: 'day' },
        { name: 'Swipe', description: '', uses: 1, per: 'turn' },
    ],
};

const ogre = (): Combatant => ({
    id: 'ogre',
    creatureId: 1,
    name: 'Ogre',
    side: 'enemy',
    initiative: 10,
    hp: 30,
    maxHp: 30,
    ac: 11,
    conditions: [],
    used: {},
});

// The fight as the tracker page would set it up, minus the page.
const setUp = (combatants: Combatant[] = [ogre()]) => {
    const fight = { combatants: ref(combatants), round: ref(0), activeIndex: ref(0), log: ref<LogEntry[]>([]) };
    const undoing = useUndo(fight, { afterUndo: () => {} });
    const combat = useCombat(fight, { ...undoing, creatureOf: (c) => (c.creatureId === 1 ? creature : undefined), selectedId: ref(null) });
    return { fight, undoing, combat };
};

// A fight that's been going for a bit: the ogre is hurt, prone, concentrating, and has used everything.
const midFight = () => {
    const setup = setUp();
    const { fight, combat } = setup;
    combat.startCombat();
    const target = fight.combatants.value[0];
    combat.damageOrHeal(target, -12);
    combat.toggleCondition(target, 'Prone');
    combat.giveTempHp(target, 5);
    target.used = { Slam: 1, Roar: 1, Swipe: 1 };
    return { ...setup, target };
};

describe('ending combat', () => {
    it('keeps wounds, conditions and per-day uses, but gives back per-encounter, round and turn ones', () => {
        const { fight, combat, target } = midFight();
        combat.endFight();

        expect(fight.round.value).toBe(0);
        expect(fight.log.value).toEqual([]);
        expect(target).toMatchObject({ hp: 18, tempHp: 5, conditions: ['Prone'] });
        expect(target.used).toEqual({ Roar: 1 });
    });

    it('resetting puts everything back as if the fight never happened', () => {
        const { fight, combat, target } = midFight();
        combat.resetFight();

        expect(fight.round.value).toBe(0);
        expect(fight.log.value).toEqual([]);
        expect(target).toMatchObject({ hp: 30, conditions: [], used: {} });
        expect(target.tempHp).toBeUndefined();
    });

    it('can be undone, history and all', () => {
        const { fight, combat, undoing } = midFight();
        const logBefore = fight.log.value.length;
        combat.endFight();
        undoing.undo();

        // Undo puts back a copy of the fight from before, everything used included.
        expect(fight.round.value).toBe(1);
        expect(fight.log.value).toHaveLength(logBefore);
        expect(fight.combatants.value[0].used).toEqual({ Slam: 1, Roar: 1, Swipe: 1 });
    });
});

describe('the combatant limit', () => {
    it('stops adding at the limit, adding as many as still fit', () => {
        const many = Array.from({ length: MAX_COMBATANTS - 2 }, (_, i) => ({ ...ogre(), id: `o${i}` }));
        const { fight, combat } = setUp(many);

        combat.addCombatants(creature, 5, 10, 'enemy');
        expect(fight.combatants.value).toHaveLength(MAX_COMBATANTS);
        expect(combat.room.value).toBe(0);

        combat.quickAdd({ name: 'One more', hp: 5, ac: 10, initiative: null, side: 'enemy', stats: [] });
        combat.addEach('Add party', [creature]);
        expect(fight.combatants.value).toHaveLength(MAX_COMBATANTS);
    });
});
