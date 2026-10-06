import type { LogEntry } from '@/lib/combatLog';
import {
    byInitiative,
    combatantsFor,
    concentrationDc,
    initiativeBonus,
    insertByInitiative,
    isDead,
    isOut,
    isStable,
    MAX_COMBATANTS,
    quickCombatant,
    restoreUses,
    rollInitiative,
    sideInfo,
    type QuickCombatantDetails,
} from '@/lib/encounter';
import type { Combatant, CombatantSide, Creature } from '@/types/tracker';
import { computed, ref, type ComputedRef, type Ref } from 'vue';
import type { FightState } from './types';
import type { UndoStep } from './useUndo';

interface CombatDeps {
    change: (label: string, mutate: () => void, options?: { replacesLog?: boolean }) => void;
    addLog: (entry: Omit<LogEntry, 'id' | 'at' | 'round'>) => void;
    undoStep: (label: string) => UndoStep;
    pushUndo: (step: UndoStep) => void;
    creatureOf: (combatant: Combatant) => Creature | undefined;
    selectedId: Ref<string | null>;
}

export interface ConcentrationCheck {
    key: string;
    combatantId: string;
    dc: number;
}

/**
 * The rules of the fight: who's in it and in what order, turns and rounds, HP, death saves,
 * concentration and conditions. Every change goes through `change()`, so it can be undone.
 */
export function useCombat(fight: FightState, { change, addLog, undoStep, pushUndo, creatureOf, selectedId }: CombatDeps) {
    const { combatants, round, activeIndex } = fight;

    // Round 0 is setup: add combatants and roll initiative. "Start combat" moves to round 1.
    const isSetup = computed(() => round.value === 0);
    const active: ComputedRef<Combatant | undefined> = computed(() => (isSetup.value ? undefined : combatants.value[activeIndex.value]));
    // How many more combatants fit before the fight can't be saved any more.
    const room = computed(() => Math.max(0, MAX_COMBATANTS - combatants.value.length));

    // --- Order ---

    // The order is the DM's: the tracker only sorts when initiative is rolled, combat starts, or they ask it to.
    // Changing the order never moves the turn off whoever currently has it.
    const keepingTurn = (reorder: () => void) => {
        const activeId = combatants.value[activeIndex.value]?.id;
        reorder();
        const index = combatants.value.findIndex((c) => c.id === activeId);
        activeIndex.value = index === -1 ? 0 : index;
    };

    // For breaking initiative ties; a quick-added combatant only has any stats typed in for it.
    const bonusOf = (combatant: Combatant) => initiativeBonus(creatureOf(combatant) ?? { stats: combatant.stats }).bonus;
    const turnOrder = byInitiative(bonusOf);

    const sortKeepingTurn = () => keepingTurn(() => combatants.value.sort(turnOrder));

    const sortByInitiative = () =>
        change('Sort', () => {
            sortKeepingTurn();
            addLog({ type: 'sorted' });
        });

    const rollAll = ({ includePlayers }: { includePlayers: boolean }) =>
        change('Roll initiative', () => {
            for (const combatant of combatants.value) {
                if (combatant.side === 'player' && !includePlayers) continue;
                // A quick-added combatant has no creature, just any stats typed in for it.
                combatant.initiative = rollInitiative(creatureOf(combatant) ?? { stats: combatant.stats });
            }
            sortKeepingTurn();
            addLog({ type: 'initiative_rolled', detail: includePlayers ? 'everyone' : undefined });
        });

    // Editing a number doesn't move anyone; "Sort" (or starting combat) does. Clearing it means
    // "not entered yet" again.
    const setInitiative = (combatant: Combatant, event: Event) => {
        const text = (event.target as HTMLInputElement).value.trim();
        if (text === '') {
            combatant.initiative = null;
            return;
        }
        const value = Number.parseInt(text, 10);
        if (Number.isNaN(value)) return;

        combatant.initiative = Math.max(-100, Math.min(1000, value));
    };

    // Drag and drop: remember the fight as it was when the drag started, so the move can be undone,
    // and keep the turn with whoever had it.
    let beforeDrag: { step: UndoStep; turnHolder: string | undefined } | null = null;
    const onDragStart = () => {
        beforeDrag = { step: undoStep('Move'), turnHolder: combatants.value[activeIndex.value]?.id };
    };
    const onDragEnd = (event: { oldIndex?: number; newIndex?: number }) => {
        if (!beforeDrag) return;
        const { step, turnHolder } = beforeDrag;
        beforeDrag = null;

        const index = combatants.value.findIndex((c) => c.id === turnHolder);
        activeIndex.value = index === -1 ? 0 : index;
        if (event.oldIndex === event.newIndex || event.newIndex === undefined) return;

        pushUndo(step);
        if (!isSetup.value) addLog({ type: 'moved', targets: [combatants.value[event.newIndex].name] });
    };

    // Keyboard alternative to dragging: Alt+↑ / Alt+↓ moves the selected combatant.
    const moveCombatant = (combatant: Combatant, direction: 1 | -1) => {
        const from = combatants.value.indexOf(combatant);
        const to = from + direction;
        if (from === -1 || to < 0 || to >= combatants.value.length) return;

        change('Move', () => {
            keepingTurn(() => {
                const [moved] = combatants.value.splice(from, 1);
                combatants.value.splice(to, 0, moved);
            });
            if (!isSetup.value) addLog({ type: 'moved', targets: [combatant.name] });
        });
    };

    // --- Who's in the fight ---

    // Slots newcomers into the order; joining mid-fight goes in the history.
    const join = (added: Combatant[]) => {
        keepingTurn(() => insertByInitiative(combatants.value, added, bonusOf));
        if (!isSetup.value) {
            for (const combatant of added) addLog({ type: 'joined', targets: [combatant.name], amount: combatant.initiative ?? undefined });
        }
    };

    // Adding stops at the limit; the buttons and dialog say so before it gets that far.
    const addCombatants = (creature: Creature, count: number, initiative: number | null, side: CombatantSide) => {
        const fits = Math.min(count, room.value);
        if (fits < 1) return;
        const added = combatantsFor(creature, fits, initiative, combatants.value, side);
        change(`Add ${creature.name}`, () => join(added));
        selectedId.value = added[0].id;
    };

    // Several creatures at once (a campaign's party), one undo step.
    const addEach = (label: string, creatures: Creature[]) => {
        const fitting = creatures.slice(0, room.value);
        if (!fitting.length) return;
        change(label, () => {
            for (const creature of fitting) join(combatantsFor(creature, 1, null, combatants.value, 'player'));
        });
    };

    const quickAdd = (details: QuickCombatantDetails) => {
        if (room.value < 1) return;
        const combatant = quickCombatant(details);
        change(`Add ${combatant.name}`, () => join([combatant]));
        selectedId.value = combatant.id;
    };

    const removeCombatant = (combatant: Combatant) => {
        const index = combatants.value.indexOf(combatant);
        if (index === -1) return;

        change(`Remove ${combatant.name}`, () => {
            combatants.value.splice(index, 1);
            // Keep the turn where it was: shift back if someone earlier left; the next in line takes over if it was theirs.
            if (index < activeIndex.value) activeIndex.value--;
            activeIndex.value = Math.min(activeIndex.value, Math.max(0, combatants.value.length - 1));
            if (!isSetup.value) addLog({ type: 'removed', targets: [combatant.name] });
        });
        selectedId.value = null;
    };

    // Allies, neutrals and enemies can change sides mid-fight; player characters can't.
    const setSide = (combatant: Combatant, side: CombatantSide) => {
        if (combatant.side === 'player' || side === 'player' || combatant.side === side) return;

        change('Side change', () => {
            combatant.side = side;
            addLog({ type: 'side', targets: [combatant.name], detail: sideInfo(side).label });
        });
    };

    // --- HP, death saves and concentration ---

    // Concentration saves waiting on the DM, one per hit taken while concentrating. Not part of the
    // fight's saved state: they're prompts, and the outcome is what goes in the history.
    const concentrationChecks = ref<ConcentrationCheck[]>([]);
    const pendingChecks = computed(() =>
        concentrationChecks.value
            .map((check) => ({ ...check, combatant: combatants.value.find((c) => c.id === check.combatantId) }))
            .filter((check) => check.combatant?.concentrating),
    );

    const ensureDeathSaves = (combatant: Combatant) => (combatant.deathSaves ??= { successes: 0, failures: 0 });

    /** Adds a death save result, and records stabilising or dying when it's the third. */
    const addDeathSave = (target: Combatant, kind: 'success' | 'failure') => {
        const saves = ensureDeathSaves(target);
        const key = kind === 'success' ? 'successes' : 'failures';
        if (saves[key] >= 3 || isDead(target)) return;

        saves[key]++;
        addLog({ type: 'death_save', targets: [target.name], detail: kind, amount: saves[key] });
        if (kind === 'failure' && saves.failures === 3) addLog({ type: 'died', targets: [target.name] });
        if (kind === 'success' && saves.successes === 3) addLog({ type: 'stabilized', targets: [target.name] });
    };

    const recordDeathSave = (target: Combatant, kind: 'success' | 'failure') =>
        change(`Death save for ${target.name}`, () => addDeathSave(target, kind));

    const stopConcentrating = (target: Combatant) => {
        delete target.concentrating;
        concentrationChecks.value = concentrationChecks.value.filter((check) => check.combatantId !== target.id);
    };

    /**
     * Changes HP and records going down, being defeated or getting back up. Damage uses up temporary
     * HP first, prompts a concentration save, and counts as a failed death save on a player who's
     * already down. With `record`, the damage or healing itself is logged too; actions log it as
     * part of the action instead.
     */
    const changeHp = (target: Combatant, delta: number, { record }: { record: boolean }) => {
        const before = target.hp;

        if (delta < 0) {
            const damage = -delta;
            const absorbed = Math.min(target.tempHp ?? 0, damage);
            if (absorbed) {
                target.tempHp = (target.tempHp ?? 0) - absorbed;
                if (!target.tempHp) delete target.tempHp;
            }
            target.hp = Math.max(0, target.hp - (damage - absorbed));
            if (record) addLog({ type: 'damage', targets: [target.name], amount: damage });

            if (target.concentrating && target.hp > 0) {
                concentrationChecks.value.push({
                    key: `${target.id}-${Date.now()}-${Math.random()}`,
                    combatantId: target.id,
                    dc: concentrationDc(damage),
                });
            }
            if (before === 0 && target.side === 'player') {
                // A stable character who's hit starts dying again.
                if (isStable(target)) ensureDeathSaves(target).successes = 0;
                addDeathSave(target, 'failure');
            }
        } else if (delta > 0) {
            target.hp = Math.min(target.maxHp, target.hp + delta);
            if (record && target.hp > before) addLog({ type: 'heal', targets: [target.name], amount: target.hp - before });
        }

        if (before > 0 && target.hp === 0) {
            addLog({ type: target.side === 'player' ? 'down' : 'defeated', targets: [target.name] });
            if (target.side === 'player') target.deathSaves = { successes: 0, failures: 0 };
            // Dropping to 0 knocks you unconscious, which ends concentration.
            if (target.concentrating) {
                stopConcentrating(target);
                addLog({ type: 'concentration', targets: [target.name], detail: 'end' });
            }
        }
        if (before === 0 && target.hp > 0) {
            addLog({ type: 'revived', targets: [target.name] });
            delete target.deathSaves;
        }
    };

    const damageOrHeal = (target: Combatant, delta: number) =>
        change(delta < 0 ? `Damage to ${target.name}` : `Healing for ${target.name}`, () => changeHp(target, delta, { record: true }));

    // Temporary HP doesn't stack: gaining some keeps whichever is higher.
    const giveTempHp = (target: Combatant, value: number) => {
        if (value <= (target.tempHp ?? 0)) return;
        change(`Temp HP for ${target.name}`, () => {
            target.tempHp = value;
            addLog({ type: 'temp_hp', targets: [target.name], amount: value });
        });
    };

    // Damage or heal several at once (area effects); each target can take half for a successful save.
    const applyGroup = (targets: { id: string; half: boolean }[], effect: 'damage' | 'heal', value: number) => {
        const chosen = targets
            .map((t) => ({ ...t, combatant: combatants.value.find((c) => c.id === t.id) }))
            .filter((t): t is { id: string; half: boolean; combatant: Combatant } => !!t.combatant);
        if (!chosen.length) return;

        change(`${effect === 'heal' ? 'Healing' : 'Damage'} to ${chosen.length}`, () => {
            for (const { combatant, half } of chosen) {
                const each = half ? Math.floor(value / 2) : value;
                if (each > 0) changeHp(combatant, effect === 'heal' ? each : -each, { record: true });
            }
        });
    };

    const toggleConcentration = (target: Combatant) =>
        change(`Concentration for ${target.name}`, () => {
            if (target.concentrating) {
                stopConcentrating(target);
                addLog({ type: 'concentration', targets: [target.name], detail: 'end' });
            } else {
                target.concentrating = true;
                addLog({ type: 'concentration', targets: [target.name], detail: 'start' });
            }
        });

    const resolveConcentration = (check: ConcentrationCheck, kept: boolean) => {
        const target = combatants.value.find((c) => c.id === check.combatantId);
        concentrationChecks.value = concentrationChecks.value.filter((c) => c.key !== check.key);
        if (!target) return;

        change(`Concentration save for ${target.name}`, () => {
            if (!kept) stopConcentrating(target);
            addLog({ type: 'concentration', targets: [target.name], detail: kept ? 'kept' : 'lost', amount: check.dc });
        });
    };

    const toggleHidden = (target: Combatant) =>
        change(target.hidden ? `Reveal ${target.name}` : `Hide ${target.name}`, () => {
            if (target.hidden) delete target.hidden;
            else target.hidden = true;
            addLog({ type: target.hidden ? 'hidden' : 'revealed', targets: [target.name] });
        });

    // --- Conditions ---

    const toggleCondition = (combatant: Combatant, condition: string) => {
        const has = combatant.conditions.includes(condition);
        change(`${condition} on ${combatant.name}`, () => {
            combatant.conditions = has ? combatant.conditions.filter((c) => c !== condition) : [...combatant.conditions, condition];
            if (has && combatant.durations) {
                delete combatant.durations[condition];
                if (!Object.keys(combatant.durations).length) delete combatant.durations;
            }
            addLog({ type: has ? 'condition_off' : 'condition_on', targets: [combatant.name], detail: condition });
        });
    };

    const setConditionDuration = (target: Combatant, condition: string, rounds: number | null) =>
        change(`${condition} duration`, () => {
            if (rounds === null) {
                if (target.durations) delete target.durations[condition];
            } else {
                target.durations = { ...(target.durations ?? {}), [condition]: rounds };
            }
            if (target.durations && !Object.keys(target.durations).length) delete target.durations;
        });

    /** The combatant whose turn is ending counts down their timed conditions; any that run out end. */
    const tickConditions = (combatant: Combatant | undefined) => {
        if (!combatant?.durations) return;
        for (const [condition, rounds] of Object.entries(combatant.durations)) {
            if (!combatant.conditions.includes(condition)) {
                delete combatant.durations[condition];
            } else if (rounds <= 1) {
                delete combatant.durations[condition];
                combatant.conditions = combatant.conditions.filter((c) => c !== condition);
                addLog({ type: 'condition_expired', targets: [combatant.name], detail: condition });
            } else {
                combatant.durations[condition] = rounds - 1;
            }
        }
        if (!Object.keys(combatant.durations).length) delete combatant.durations;
    };

    // --- Turns ---

    /** Starts the turn of whoever is now active: gives back per-turn uses and records it. */
    const beginTurn = () => {
        const combatant = combatants.value[activeIndex.value];
        if (!combatant) return;
        restoreUses(combatant, creatureOf(combatant), ['turn']);
        addLog({ type: 'turn', actor: combatant.name });
    };

    const startCombat = () => {
        if (combatants.value.length === 0) return;

        change('Start combat', () => {
            combatants.value.sort(turnOrder);
            round.value = 1;
            // Skip anyone already out of the fight.
            const first = combatants.value.findIndex((c) => !isOut(c));
            activeIndex.value = first === -1 ? 0 : first;
            addLog({ type: 'combat_started' });
            addLog({ type: 'round' });
            beginTurn();
        });
        selectedId.value = null;
    };

    const step = (direction: 1 | -1) => {
        const count = combatants.value.length;
        if (isSetup.value || count === 0 || combatants.value.every(isOut)) return;

        // Work out where the turn goes before changing anything, so a no-op doesn't cost an undo step.
        let index = activeIndex.value;
        let nextRound = round.value;
        do {
            index += direction;
            if (index >= count) {
                index = 0;
                nextRound++;
            } else if (index < 0) {
                if (nextRound === 1) return;
                index = count - 1;
                nextRound--;
            }
        } while (isOut(combatants.value[index]));

        change(direction === 1 ? 'Next turn' : 'Previous turn', () => {
            // Timed conditions count down as their bearer's turn ends (going forward only).
            if (direction === 1) tickConditions(combatants.value[activeIndex.value]);

            const newRound = nextRound > round.value;
            round.value = nextRound;
            activeIndex.value = index;

            if (direction === -1) {
                addLog({ type: 'turn', actor: combatants.value[index].name, detail: 'back' });
                return;
            }
            if (newRound) {
                addLog({ type: 'round' });
                for (const combatant of combatants.value) restoreUses(combatant, creatureOf(combatant), ['round']);
            }
            beginTurn();
        });
        selectedId.value = null;
    };

    /**
     * The fight is over: back to setup with the history cleared, but everyone as they are now. HP,
     * temporary HP, conditions and concentration carry on into whatever comes next, and so do actions
     * limited per day; anything limited per turn, round or encounter comes back. It replaces the
     * history, so the undo step keeps a copy.
     */
    const endFight = () =>
        change(
            'End combat',
            () => {
                for (const combatant of combatants.value) restoreUses(combatant, creatureOf(combatant), ['turn', 'round', 'encounter']);
                concentrationChecks.value = [];
                round.value = 0;
                activeIndex.value = 0;
                fight.log.value = [];
            },
            { replacesLog: true },
        );

    /**
     * Back to setup as if the fight never happened: full HP, no conditions or used actions, and no
     * history. It replaces the history, so the undo step keeps a copy and undo brings it all back.
     * Who's hidden is set-up rather than fight state, so it stays.
     */
    const resetFight = () =>
        change(
            'Reset',
            () => {
                combatants.value.forEach((combatant) => {
                    combatant.hp = combatant.maxHp;
                    combatant.conditions = [];
                    combatant.used = {};
                    delete combatant.durations;
                    delete combatant.tempHp;
                    delete combatant.concentrating;
                    delete combatant.deathSaves;
                });
                concentrationChecks.value = [];
                round.value = 0;
                activeIndex.value = 0;
                fight.log.value = [];
            },
            { replacesLog: true },
        );

    return {
        isSetup,
        active,
        sortByInitiative,
        rollAll,
        setInitiative,
        onDragStart,
        onDragEnd,
        moveCombatant,
        addCombatants,
        addEach,
        quickAdd,
        removeCombatant,
        setSide,
        concentrationChecks,
        pendingChecks,
        recordDeathSave,
        changeHp,
        damageOrHeal,
        giveTempHp,
        applyGroup,
        toggleConcentration,
        resolveConcentration,
        toggleHidden,
        toggleCondition,
        setConditionDuration,
        startCombat,
        step,
        endFight,
        resetFight,
        room,
    };
}
