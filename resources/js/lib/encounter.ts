import { modifier } from '@/lib/stats';
import type { Combatant, CombatantSide, Creature, CreatureAction, CreatureResource, CreatureStat, LimitPeriod } from '@/types/tracker';
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
        if (Array.isArray(combatant.spent)) delete combatant.spent;
        if (Array.isArray(combatant.durations)) delete combatant.durations;
        if (combatant.creatureId === undefined) combatant.creatureId = null;
        // Older saves used 0 for a player who hadn't entered their initiative yet.
        if (combatant.initiative === 0 && combatant.side === 'player') combatant.initiative = null;

        const creature = combatant.creatureId === null ? undefined : creaturesById.get(combatant.creatureId);
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

// The kinds of limit an action can have; it has at most one.
export type LimitKind = 'uses' | 'recharge' | 'cooldown' | 'resource';

export const limitKind = (action: CreatureAction): LimitKind | null => {
    if (action.resource) return 'resource';
    if (action.recharge) return 'recharge';
    if (action.cooldown) return 'cooldown';
    if (action.uses) return 'uses';
    return null;
};

const periodShort = (per: LimitPeriod | null) => limitPeriods.find((p) => p.value === per)?.short ?? 'Day';

const plural = (count: number | string, word: string) => `${count} ${word}${String(count) === '1' ? '' : 's'}`;

/**
 * Stat-block label for a limit: "3/Day", "Recharge 5–6", "Recharge 7–8 (d8)", "Cooldown 1d4 rounds",
 * "Legendary actions: 2". Empty for unlimited actions.
 */
export const limitLabel = (action: CreatureAction): string => {
    switch (limitKind(action)) {
        case 'uses':
            return `${action.uses}/${periodShort(action.per)}`;
        case 'recharge': {
            const { die, min } = action.recharge!;
            return `Recharge ${min === die ? die : `${min}–${die}`}${die === 6 ? '' : ` (d${die})`}`;
        }
        case 'cooldown':
            return `Cooldown ${plural(action.cooldown!, 'round')}`;
        case 'resource':
            return `${action.resource}: ${action.cost}`;
        default:
            return '';
    }
};

/** Uses left for an action limited to a number of uses, or null for any other kind. */
export const usesLeft = (combatant: Combatant, action: CreatureAction): number | null =>
    limitKind(action) === 'uses' ? Math.max(0, action.uses! - (combatant.used[action.name] ?? 0)) : null;

/** How much of a resource a combatant has left. */
export const resourceLeft = (combatant: Combatant, resource: CreatureResource) => Math.max(0, resource.max - (combatant.spent?.[resource.name] ?? 0));

/** Whether an action can be used now, and a few words on where it stands ("2/3 left", "Ready in 2 rounds"). */
export const actionStatus = (combatant: Combatant, action: CreatureAction, creature: Creature | undefined): { ready: boolean; status: string } => {
    const state = combatant.used[action.name] ?? 0;
    switch (limitKind(action)) {
        case 'uses': {
            const left = usesLeft(combatant, action)!;
            return { ready: left > 0, status: `${left}/${action.uses} left` };
        }
        case 'recharge':
            return state ? { ready: false, status: 'Spent: rolls to recharge each turn' } : { ready: true, status: 'Ready' };
        case 'cooldown':
            return state ? { ready: false, status: `Ready in ${plural(state, 'round')}` } : { ready: true, status: 'Ready' };
        case 'resource': {
            const resource = creature?.resources.find((r) => r.name === action.resource);
            // The resource was renamed or removed since: nothing to spend, so nothing stops it.
            if (!resource) return { ready: true, status: '' };
            const left = resourceLeft(combatant, resource);
            // The resource's name is in the limit label next to this, so it isn't repeated here.
            return { ready: left >= action.cost!, status: `${left}/${resource.max} left` };
        }
        default:
            return { ready: true, status: '' };
    }
};

/**
 * Rolls dice like "1d4", "2d6+1" or "d8", or reads a plain number. Never below 0; anything it can't read is 0.
 */
export const rollDice = (expression: string): number => {
    const text = expression.trim().toLowerCase();
    if (/^\d+$/.test(text)) return Number(text);
    const match = /^(\d*)d(\d+)([+-]\d+)?$/.exec(text);
    if (!match) return 0;
    const [count, sides, bonus] = [Number(match[1] || 1), Number(match[2]), Number(match[3] ?? 0)];
    let total = bonus;
    for (let i = 0; i < count; i++) total += rollDie(Math.max(1, sides));
    return Math.max(0, total);
};

/** Records one use of an action against its limit: a use, spending it, starting its cooldown or paying its cost. */
export const spendAction = (combatant: Combatant, action: CreatureAction) => {
    const { name } = action;
    switch (limitKind(action)) {
        case 'uses':
            combatant.used[name] = (combatant.used[name] ?? 0) + 1;
            break;
        case 'recharge':
            combatant.used[name] = 1;
            break;
        case 'cooldown': {
            // A cooldown of 0 rounds (say, a 1d4-1 that rolled 0) leaves it ready.
            const rounds = Math.min(999, rollDice(action.cooldown!));
            if (rounds > 0) combatant.used[name] = rounds;
            break;
        }
        case 'resource': {
            const spent = (combatant.spent ??= {});
            spent[action.resource!] = Math.min(999, (spent[action.resource!] ?? 0) + action.cost!);
            break;
        }
    }
};

/** What happened to a limited action at the start of its owner's turn, for the history. */
export interface TurnStartEvent {
    type: 'recharged' | 'not_recharged';
    action: string;
    // The recharge roll; absent when a cooldown ran out.
    roll?: number;
}

/**
 * The start of a combatant's own turn for its limited actions: spent recharge actions roll to come
 * back, and cooldowns count down a round (coming back at 0). Updates the combatant in place.
 */
export const startTurnFor = (combatant: Combatant, creature: Creature | undefined): TurnStartEvent[] => {
    const events: TurnStartEvent[] = [];
    for (const action of creature?.actions ?? []) {
        const state = combatant.used[action.name];
        if (!state) continue;

        const kind = limitKind(action);
        if (kind === 'recharge') {
            const roll = rollDie(action.recharge!.die);
            const back = roll >= action.recharge!.min;
            if (back) delete combatant.used[action.name];
            events.push({ type: back ? 'recharged' : 'not_recharged', action: action.name, roll });
        } else if (kind === 'cooldown') {
            if (state > 1) {
                combatant.used[action.name] = state - 1;
            } else {
                delete combatant.used[action.name];
                events.push({ type: 'recharged', action: action.name });
            }
        }
    }
    return events;
};

/**
 * Refills whatever comes back on one of `periods`: actions' uses and resources with that period.
 * Recharges and cooldowns only last the fight, so they come back with the "encounter" period too.
 */
export const restoreUses = (combatant: Combatant, creature: Creature | undefined, periods: LimitPeriod[]) => {
    for (const action of creature?.actions ?? []) {
        const kind = limitKind(action);
        const comesBack =
            (kind === 'uses' && periods.includes(action.per!)) || ((kind === 'recharge' || kind === 'cooldown') && periods.includes('encounter'));
        if (comesBack) delete combatant.used[action.name];
    }
    if (!combatant.spent) return;
    for (const resource of creature?.resources ?? []) {
        if (periods.includes(resource.per)) delete combatant.spent[resource.name];
    }
    if (!Object.keys(combatant.spent).length) delete combatant.spent;
};

/** Spends or gives back some of a resource by hand (a spell slot used for a spell not listed, say). */
export const adjustResource = (combatant: Combatant, resource: CreatureResource, delta: number) => {
    const spent = Math.max(0, Math.min(resource.max, (combatant.spent?.[resource.name] ?? 0) - delta));
    combatant.spent = { ...(combatant.spent ?? {}), [resource.name]: spent };
    if (!spent) delete combatant.spent[resource.name];
    if (!Object.keys(combatant.spent).length) delete combatant.spent;
};

// The most combatants one fight can have. Mirrors EncounterPayload::MAX_COMBATANTS: the server won't
// save (or show players) a bigger fight, so the tracker stops adding at this point instead.
export const MAX_COMBATANTS = 100;

// A combatant's initiative bonus, which needs the creatures to look up (see initiativeBonus()).
export type BonusOf = (combatant: Combatant) => number;

/**
 * Turn order: highest initiative first; a tie goes to the higher initiative bonus (the usual table
 * rule, since 5e leaves ties to the DM). Anyone without an initiative yet goes last. Anyone still
 * tied keeps their place, as sort is stable, so the DM can settle it by dragging.
 */
export const byInitiative =
    (bonusOf: BonusOf) =>
    (a: Combatant, b: Combatant): number => {
        if (a.initiative === null || b.initiative === null) return (a.initiative === null ? 1 : 0) - (b.initiative === null ? 1 : 0);
        return b.initiative - a.initiative || bonusOf(b) - bonusOf(a);
    };

// How long a condition lasts, in rounds. Counted down at the end of the affected combatant's turn,
// so "until the end of its next turn" is 1. Null means until it's removed by hand.
export const conditionDurations: { value: number | null; label: string }[] = [
    { value: null, label: 'Until removed' },
    { value: 1, label: '1 round' },
    { value: 2, label: '2 rounds' },
    { value: 3, label: '3 rounds' },
    { value: 5, label: '5 rounds' },
    { value: 10, label: '1 minute (10 rounds)' },
    { value: 100, label: '10 minutes (100 rounds)' },
];

// The d20 rule for keeping concentration after taking damage.
export const concentrationDc = (damage: number) => Math.max(10, Math.floor(damage / 2));

/** A player character who has failed three death saves. Out of the fight for good. */
export const isDead = (combatant: Combatant) => combatant.side === 'player' && (combatant.deathSaves?.failures ?? 0) >= 3;

/** A player character at 0 HP who has made three successful death saves. */
export const isStable = (combatant: Combatant) =>
    combatant.side === 'player' && combatant.hp === 0 && (combatant.deathSaves?.successes ?? 0) >= 3 && !isDead(combatant);

/**
 * Out of the fight and skipped in the turn order: defeated non-players, and players who have died.
 * Players who are down but not dead still get their turn (death saves, being revived).
 */
export const isOut = (combatant: Combatant) => (combatant.hp <= 0 && combatant.side !== 'player') || isDead(combatant);

export const roundsLeft = (rounds: number) => (rounds === 1 ? '1 round left' : `${rounds} rounds left`);

export const hpPercent = (combatant: Pick<Combatant, 'hp' | 'maxHp'>) => Math.round((combatant.hp / combatant.maxHp) * 100);

export const hpBarColor = (combatant: Pick<Combatant, 'hp' | 'maxHp'>) => {
    const percent = hpPercent(combatant);
    if (percent > 50) return 'bg-emerald-500';
    if (percent > 25) return 'bg-amber-500';
    return 'bg-red-500';
};

/**
 * Adds combatants without disturbing a hand-arranged order: each one goes in front of the first
 * combatant it beats (higher initiative, or the same with a higher bonus), so it lands after everyone
 * it ties with completely. Updates `list` in place.
 */
export const insertByInitiative = (list: Combatant[], added: Combatant[], bonusOf: BonusOf) => {
    const compare = byInitiative(bonusOf);
    for (const combatant of added) {
        const index = list.findIndex((c) => compare(c, combatant) > 0);
        list.splice(index === -1 ? list.length : index, 0, combatant);
    }
};

/** A fair roll of one die with `sides` faces. */
export const rollDie = (sides: number) => {
    const values = new Uint32Array(1);
    crypto.getRandomValues(values);
    return (values[0] % sides) + 1;
};

// Whatever has initiative: a creature, or a quick-added combatant (stats only).
interface RollsInitiative {
    initiativeBonus?: number | null;
    stats?: CreatureStat[];
}

/**
 * What gets added to the d20, and where it comes from: the creature's own initiative bonus if it has
 * one (any game system, and D&D 2024 stat blocks that print it), otherwise the d20 convention of a
 * stat called DEX or Dexterity, otherwise nothing.
 */
export const initiativeBonus = (who: RollsInitiative | undefined): { bonus: number; from: 'bonus' | 'DEX' | null } => {
    if (who?.initiativeBonus !== null && who?.initiativeBonus !== undefined) return { bonus: who.initiativeBonus, from: 'bonus' };
    const dex = who?.stats?.find((s) => ['dex', 'dexterity'].includes(s.label.trim().toLowerCase()));
    return dex ? { bonus: modifier(dex.value), from: 'DEX' } : { bonus: 0, from: null };
};

/** "d20 + 3 (initiative bonus)", "d20 − 1 (DEX)" or just "d20", for showing how a roll is made. */
export const initiativeFormula = (who: RollsInitiative | undefined) => {
    const { bonus, from } = initiativeBonus(who);
    if (from === null) return 'd20';
    const sign = bonus < 0 ? '−' : '+';
    return `d20 ${sign} ${Math.abs(bonus)} (${from === 'bonus' ? 'initiative bonus' : 'DEX'})`;
};

// Can come out at 0 or below with a penalty, which is allowed.
export const rollInitiative = (who: RollsInitiative | undefined) => rollDie(20) + initiativeBonus(who).bonus;

// The six d20 ability scores, offered as a starting point when typing stats in by hand.
export const defaultStatLabels = ['STR', 'DEX', 'CON', 'INT', 'WIS', 'CHA'];

const newId = () => (typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);

export interface QuickCombatantDetails {
    name: string;
    hp: number;
    ac: number;
    // Null when it's left for them to enter.
    initiative: number | null;
    side: CombatantSide;
    stats: CreatureStat[];
}

/**
 * A combatant added straight into the encounter without a compendium entry, e.g. a player
 * character a guest types in at the table. It has no creature, so it carries any stats itself
 * and has no actions.
 */
export const quickCombatant = (details: QuickCombatantDetails): Combatant => ({
    id: newId(),
    creatureId: null,
    name: details.name,
    side: details.side,
    initiative: details.initiative,
    hp: details.hp,
    maxHp: details.hp,
    ac: details.ac,
    conditions: [],
    used: {},
    ...(details.stats.length ? { stats: details.stats } : {}),
});

/**
 * Combatants for `count` copies of a creature. They get numbered ("Goblin 1", "Goblin 2")
 * when there's more than one in the fight, continuing from any already there.
 * A null initiative means "roll it": each monster or NPC rolls separately, and players are left
 * without one because players roll their own.
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
        initiative: initiative ?? (isPlayer ? null : rollInitiative(creature)),
        hp: creature.hp,
        maxHp: creature.hp,
        ac: creature.ac,
        conditions: [],
        used: {},
    }));
};
