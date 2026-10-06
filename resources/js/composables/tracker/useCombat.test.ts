import type { LogEntry } from '@/lib/combatLog';
import { MAX_COMBATANTS, spendAction } from '@/lib/encounter';
import type { Combatant, Creature, CreatureAction } from '@/types/tracker';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useCombat } from './useCombat';
import { useUndo } from './useUndo';

const unlimited = { uses: null, per: null, recharge: null, cooldown: null, resource: null, cost: null };
const action = (name: string, limit: Partial<CreatureAction>): CreatureAction => ({ name, description: '', ...unlimited, ...limit });

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
        action('Slam', { uses: 1, per: 'encounter' }),
        action('Roar', { uses: 1, per: 'day' }),
        action('Swipe', { uses: 1, per: 'turn' }),
        action('Breath', { recharge: { die: 6, min: 5 } }),
        action('Stomp', { cooldown: '2' }),
        action('Tail', { resource: 'Legendary actions', cost: 2 }),
        action('Fireball', { resource: 'Slots', cost: 1 }),
    ],
    resources: [
        { name: 'Legendary actions', max: 3, per: 'turn' },
        { name: 'Slots', max: 2, per: 'day' },
    ],
};

// Every die comes up 1 (the lowest roll), so recharge rolls can be made to fail on purpose.
const rollOnes = () => vi.spyOn(globalThis.crypto, 'getRandomValues').mockImplementation((array) => array);
afterEach(() => vi.restoreAllMocks());

const byName = (name: string) => creature.actions.find((a) => a.name === name)!;

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

describe('limited actions and resources', () => {
    // Two ogres, so turns go back and forth: the first one's turn comes round each round.
    const twoOgres = () => {
        const setup = setUp([ogre(), { ...ogre(), id: 'other', name: 'Other ogre', initiative: 5 }]);
        setup.combat.startCombat();
        return { ...setup, target: setup.fight.combatants.value[0] };
    };

    it('spends resources and gets per-turn ones back at the start of its own turn', () => {
        const { combat, target } = twoOgres();
        spendAction(target, byName('Tail'));
        spendAction(target, byName('Fireball'));
        expect(target.spent).toEqual({ 'Legendary actions': 2, Slots: 1 });

        combat.step(1); // the other ogre's turn: nothing comes back yet
        expect(target.spent).toEqual({ 'Legendary actions': 2, Slots: 1 });
        combat.step(1); // its own turn again
        expect(target.spent).toEqual({ Slots: 1 });
    });

    it('rolls to recharge at the start of its turn, and records the roll for the DM', () => {
        const { fight, combat, target } = twoOgres();
        spendAction(target, byName('Breath'));
        expect(target.used.Breath).toBe(1);

        rollOnes();
        combat.step(1);
        combat.step(1);
        expect(target.used.Breath).toBe(1);
        expect(fight.log.value.at(-1)).toMatchObject({ type: 'not_recharged', actor: 'Ogre', detail: 'Breath', amount: 1 });
    });

    it('counts cooldowns down on its own turns and says when it is ready', () => {
        const { fight, combat, target } = twoOgres();
        spendAction(target, byName('Stomp'));
        expect(target.used.Stomp).toBe(2);

        combat.step(1);
        combat.step(1);
        expect(target.used.Stomp).toBe(1);
        combat.step(1);
        combat.step(1);
        expect(target.used.Stomp).toBeUndefined();
        expect(fight.log.value.at(-1)).toMatchObject({ type: 'recharged', detail: 'Stomp' });
        expect(fight.log.value.at(-1)?.amount).toBeUndefined();
    });

    it('ending combat brings back recharges, cooldowns and per-turn resources, but not per-day ones', () => {
        const { combat, target } = twoOgres();
        for (const name of ['Breath', 'Stomp', 'Tail', 'Fireball']) spendAction(target, byName(name));
        combat.endFight();

        expect(target.used).toEqual({});
        expect(target.spent).toEqual({ Slots: 1 });

        combat.resetFight();
        expect(target.spent).toBeUndefined();
    });

    it('lets the DM spend and give back resources by hand, within the pool', () => {
        const { combat, target } = twoOgres();
        const slots = creature.resources[1];
        combat.changeResource(target, slots, -1);
        combat.changeResource(target, slots, -1);
        combat.changeResource(target, slots, -1);
        expect(target.spent).toEqual({ Slots: 2 });

        combat.changeResource(target, slots, 1);
        combat.changeResource(target, slots, 1);
        combat.changeResource(target, slots, 1);
        expect(target.spent).toBeUndefined();
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
