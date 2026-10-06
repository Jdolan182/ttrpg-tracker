import type { Combatant, Creature, CreatureAction } from '@/types/tracker';
import { describe, expect, it } from 'vitest';
import {
    actionStatus,
    byInitiative,
    combatantsFor,
    concentrationDc,
    defaultSide,
    initiativeBonus,
    initiativeFormula,
    insertByInitiative,
    isDead,
    isStable,
    limitLabel,
    normalizeCombatants,
    quickCombatant,
    rollDice,
    rollInitiative,
} from './encounter';

const creature = (overrides: Partial<Creature> = {}): Creature => ({
    id: 1,
    kind: 'monster',
    source: 'homebrew',
    name: 'Goblin',
    summary: '',
    rating: '',
    hp: 7,
    ac: 15,
    initiativeBonus: null,
    speed: '30 ft.',
    stats: [{ label: 'DEX', value: 14 }],
    traits: [],
    actions: [],
    resources: [],
    ...overrides,
});

const combatant = (name: string, initiative: number, overrides: Partial<Combatant> = {}): Combatant => ({
    id: name.toLowerCase(),
    creatureId: null,
    name,
    side: 'enemy',
    initiative,
    hp: 10,
    maxHp: 10,
    ac: 12,
    conditions: [],
    used: {},
    ...overrides,
});

describe('limits', () => {
    const none = { uses: null, per: null, recharge: null, cooldown: null, resource: null, cost: null };
    const action = (limit: Partial<CreatureAction>): CreatureAction => ({ name: 'Breath', description: '', ...none, ...limit });

    it('labels each kind the way a stat block would', () => {
        expect(limitLabel(action({}))).toBe('');
        expect(limitLabel(action({ uses: 3, per: 'day' }))).toBe('3/Day');
        expect(limitLabel(action({ recharge: { die: 6, min: 5 } }))).toBe('Recharge 5–6');
        expect(limitLabel(action({ recharge: { die: 6, min: 6 } }))).toBe('Recharge 6');
        expect(limitLabel(action({ recharge: { die: 8, min: 7 } }))).toBe('Recharge 7–8 (d8)');
        expect(limitLabel(action({ cooldown: '1d4' }))).toBe('Cooldown 1d4 rounds');
        expect(limitLabel(action({ cooldown: '1' }))).toBe('Cooldown 1 round');
        expect(limitLabel(action({ resource: 'Mana', cost: 2 }))).toBe('Mana: 2');
    });

    it('says whether a resource action is affordable', () => {
        const mage = creature({ resources: [{ name: 'Mana', max: 3, per: 'encounter' }] });
        const zap = action({ resource: 'Mana', cost: 2 });
        const who = combatant('Mage', 10);
        expect(actionStatus(who, zap, mage)).toEqual({ ready: true, status: '3/3 left' });
        who.spent = { Mana: 2 };
        expect(actionStatus(who, zap, mage)).toEqual({ ready: false, status: '1/3 left' });
        // A resource that's since been removed doesn't block anything.
        expect(actionStatus(who, zap, creature()).ready).toBe(true);
    });

    it('rolls dice for cooldowns, and reads plain numbers', () => {
        expect(rollDice('3')).toBe(3);
        expect(rollDice('nonsense')).toBe(0);
        for (let i = 0; i < 50; i++) {
            const roll = rollDice('2d4+1');
            expect(roll).toBeGreaterThanOrEqual(3);
            expect(roll).toBeLessThanOrEqual(9);
        }
        expect(rollDice('d1-5')).toBe(0);
    });
});

describe('initiative bonus', () => {
    it("uses the creature's own bonus first, even when it has DEX", () => {
        expect(initiativeBonus(creature({ initiativeBonus: 7 }))).toEqual({ bonus: 7, from: 'bonus' });
        // 0 is a real bonus, not "unset".
        expect(initiativeBonus(creature({ initiativeBonus: 0 }))).toEqual({ bonus: 0, from: 'bonus' });
    });

    it('falls back to the DEX modifier, then nothing', () => {
        expect(initiativeBonus(creature())).toEqual({ bonus: 2, from: 'DEX' });
        expect(initiativeBonus({ stats: [{ label: 'Dexterity', value: 7 }] })).toEqual({ bonus: -2, from: 'DEX' });
        expect(initiativeBonus({ stats: [{ label: 'Might', value: 18 }] })).toEqual({ bonus: 0, from: null });
        expect(initiativeBonus(undefined)).toEqual({ bonus: 0, from: null });
    });

    it('describes how the roll is made', () => {
        expect(initiativeFormula(creature({ initiativeBonus: 3 }))).toBe('d20 + 3 (initiative bonus)');
        expect(initiativeFormula({ stats: [{ label: 'DEX', value: 8 }] })).toBe('d20 − 1 (DEX)');
        expect(initiativeFormula({})).toBe('d20');
    });

    it('rolls a d20 plus the bonus, which can go below 1', () => {
        for (let i = 0; i < 200; i++) {
            const roll = rollInitiative(creature({ initiativeBonus: -5 }));
            expect(roll).toBeGreaterThanOrEqual(-4);
            expect(roll).toBeLessThanOrEqual(15);
        }
    });
});

describe('turn order', () => {
    const bonusOf = (c: Combatant) => c.stats?.[0]?.value ?? 0;

    it('sorts by initiative, then the higher bonus, then keeps the existing order', () => {
        const list = [
            combatant('Slow', 18, { stats: [{ label: 'bonus', value: 1 }] }),
            combatant('Low', 5),
            combatant('Quick', 18, { stats: [{ label: 'bonus', value: 3 }] }),
            combatant('Also quick', 18, { stats: [{ label: 'bonus', value: 3 }] }),
        ];
        expect(list.sort(byInitiative(bonusOf)).map((c) => c.name)).toEqual(['Quick', 'Also quick', 'Slow', 'Low']);
    });

    it('puts anyone without an initiative yet last, and treats a real 0 as a roll', () => {
        const list = [combatant('Unrolled', 0, { initiative: null }), combatant('Zero', 0), combatant('Low', -2), combatant('High', 12)];
        expect(list.sort(byInitiative(() => 0)).map((c) => c.name)).toEqual(['High', 'Zero', 'Low', 'Unrolled']);
    });

    it('slots someone without an initiative in at the end', () => {
        const list = [combatant('A', 20), combatant('B', -3)];
        insertByInitiative(list, [combatant('New', 0, { initiative: null })], () => 0);
        expect(list.map((c) => c.name)).toEqual(['A', 'B', 'New']);
    });

    it('slots new combatants in after everyone they fully tie with', () => {
        const list = [combatant('A', 20), combatant('B', 12), combatant('C', 12), combatant('D', 3)];
        insertByInitiative(list, [combatant('New', 12)], () => 0);
        expect(list.map((c) => c.name)).toEqual(['A', 'B', 'C', 'New', 'D']);
    });

    it('lets a higher bonus jump ahead of a tie when slotting in', () => {
        const list = [combatant('A', 12), combatant('B', 3)];
        insertByInitiative(list, [combatant('New', 12, { stats: [{ label: 'bonus', value: 5 }] })], bonusOf);
        expect(list.map((c) => c.name)).toEqual(['New', 'A', 'B']);
    });
});

describe('adding combatants', () => {
    it('numbers copies, carrying on from any already in the fight', () => {
        const existing = combatantsFor(creature(), 2, 10, []);
        expect(existing.map((c) => c.name)).toEqual(['Goblin 1', 'Goblin 2']);
        expect(combatantsFor(creature(), 1, 10, existing)[0].name).toBe('Goblin 3');
        expect(combatantsFor(creature(), 1, 10, [])[0].name).toBe('Goblin');
    });

    it("leaves players' initiative for them to enter, and keeps them on the player side", () => {
        const [player] = combatantsFor(creature({ kind: 'player', name: 'Aria' }), 1, null, [], 'enemy');
        expect(player).toMatchObject({ initiative: null, side: 'player' });
    });

    it('never puts a monster on the player side', () => {
        expect(combatantsFor(creature(), 1, 10, [], 'player')[0].side).toBe('enemy');
        expect(defaultSide({ kind: 'npc' })).toBe('neutral');
    });

    it('quick-adds with only the stats given', () => {
        expect(quickCombatant({ name: 'Aria', hp: 30, ac: 15, initiative: 0, side: 'player', stats: [] })).not.toHaveProperty('stats');
        expect(
            quickCombatant({ name: 'Aria', hp: 30, ac: 15, initiative: 0, side: 'player', stats: [{ label: 'DEX', value: 16 }] }).stats,
        ).toHaveLength(1);
    });
});

describe('older saves', () => {
    it("turns a player's old 0 (not entered yet) into no initiative, and leaves everyone else's alone", () => {
        const [player, monster] = normalizeCombatants([combatant('Aria', 0, { side: 'player' }), combatant('Slug', 0)], new Map());
        expect(player.initiative).toBeNull();
        expect(monster.initiative).toBe(0);
    });
});

describe('fight state', () => {
    it('works out the concentration save DC', () => {
        expect(concentrationDc(4)).toBe(10);
        expect(concentrationDc(23)).toBe(11);
    });

    it('tells dead and stable player characters apart', () => {
        const down = combatant('Aria', 10, { side: 'player', hp: 0 });
        expect(isStable({ ...down, deathSaves: { successes: 3, failures: 1 } })).toBe(true);
        expect(isDead({ ...down, deathSaves: { successes: 3, failures: 3 } })).toBe(true);
        expect(isStable({ ...down, deathSaves: { successes: 3, failures: 3 } })).toBe(false);
        // Only player characters make death saves.
        expect(isDead({ ...down, side: 'enemy', deathSaves: { successes: 0, failures: 3 } })).toBe(false);
    });
});
