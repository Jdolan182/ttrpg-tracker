<script setup lang="ts">
import AddCombatantDialog from '@/components/AddCombatantDialog.vue';
import CombatHistory from '@/components/CombatHistory.vue';
import StatBlock from '@/components/StatBlock.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import UseActionDialog from '@/components/UseActionDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { logEntry, MAX_LOG_ENTRIES, type LogEntry } from '@/lib/combatLog';
import {
    byInitiative,
    combatantsFor,
    conditionIcon,
    conditions,
    insertByInitiative,
    limitLabel,
    normalizeCombatants,
    restoreUses,
    rollInitiative,
    sideInfo,
    switchableSides,
    usesLeft,
    type ActionEffect,
} from '@/lib/encounter';
import { readTracker, trackerStorageKey, writeTracker } from '@/lib/trackerStorage';
import { fingerprint, plainCopy } from '@/lib/utils';
import type { SharedData } from '@/types';
import type { Combatant, CombatantSide, Creature, CreatureAction, Encounter, EncounterSummary } from '@/types/tracker';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDownWideNarrow,
    ArrowLeft,
    ArrowRight,
    ChevronDown,
    Dices,
    Download,
    FilePlus2,
    GripVertical,
    MonitorPlay,
    Play,
    Plus,
    RotateCcw,
    Save,
    Trash2,
    Undo2,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { VueDraggable } from 'vue-draggable-plus';

const props = defineProps<{
    // The signed-in user's encounters by name, most recently updated first. Always empty for guests.
    savedEncounters: EncounterSummary[];
    // The one saved encounter sent in full: the one asked for (?encounter=), else the most recent.
    openEncounter: Encounter | null;
    // Everything the user can add: SRD creatures, plus their own when signed in.
    creatures: Creature[];
}>();

const page = usePage<SharedData>();
const user = page.props.auth.user;
const isGuest = !user;

const encounterId = ref<number | null>(null);
const name = ref('');
const combatants = ref<Combatant[]>([]);
const round = ref(1);
const activeIndex = ref(0);
const log = ref<LogEntry[]>([]);
const selectedId = ref<string | null>(null);
const amount = ref('');
const addOpen = ref(false);
const saving = ref(false);
const saveError = ref('');
const panelTab = ref<'combatant' | 'history'>('combatant');

const creaturesById = computed(() => new Map(props.creatures.map((c) => [c.id, c])));
// Round 0 is setup: add combatants and roll initiative. "Start combat" moves to round 1.
const isSetup = computed(() => round.value === 0);
const active = computed<Combatant | undefined>(() => (isSetup.value ? undefined : combatants.value[activeIndex.value]));
const selected = computed(() => combatants.value.find((c) => c.id === selectedId.value) ?? active.value ?? combatants.value[0]);
const selectedCreature = computed(() => (selected.value ? creaturesById.value.get(selected.value.creatureId) : undefined));
const creatureOf = (combatant: Combatant) => creaturesById.value.get(combatant.creatureId);

// Unsaved-changes tracking: compare a fingerprint of the current fight with one taken when it was
// last saved or opened. A fingerprint rather than a copy, so it can be kept in browser storage too.
const currentFingerprint = () =>
    fingerprint(
        JSON.stringify({ name: name.value, round: round.value, activeIndex: activeIndex.value, combatants: combatants.value, log: log.value }),
    );
const savedFingerprint = ref('');
const isDirty = computed(() => currentFingerprint() !== savedFingerprint.value);
const markSaved = () => {
    savedFingerprint.value = currentFingerprint();
};

// Undo: every change to the fight snapshots it first, so the last few can be stepped back one at a time.
// Kept in memory only; a refresh starts a fresh undo history (the combat history itself is kept).
//
// The history isn't copied into each step: changes only ever add entries to the end, so a step just
// remembers the newest entry at the time and undo removes everything after it. That keeps undo small
// however long the history gets. Reset is the one change that replaces the history, so it keeps a copy.
const MAX_UNDO = 100;
interface UndoStep {
    label: string;
    // Combatants, round and turn before the change.
    state: string;
    // Newest history entry before the change (null when the history was empty).
    lastLogId: string | null;
    // The whole history, only for changes that replace it rather than add to it.
    fullLog?: string;
}
const undoStack = ref<UndoStep[]>([]);
const lastUndo = computed(() => undoStack.value[undoStack.value.length - 1]);

const undoStep = (label: string, { replacesLog = false } = {}): UndoStep => ({
    label,
    state: JSON.stringify({ combatants: combatants.value, round: round.value, activeIndex: activeIndex.value }),
    lastLogId: log.value[log.value.length - 1]?.id ?? null,
    fullLog: replacesLog ? JSON.stringify(log.value) : undefined,
});

const pushUndo = (step: UndoStep) => {
    undoStack.value.push(step);
    if (undoStack.value.length > MAX_UNDO) undoStack.value.shift();
};

const addLog = (entry: Omit<LogEntry, 'id' | 'at' | 'round'>) => {
    log.value.push(logEntry(round.value, entry));
    if (log.value.length > MAX_LOG_ENTRIES) log.value.splice(0, log.value.length - MAX_LOG_ENTRIES);
};

/**
 * Makes one undoable change to the fight. `label` is what the Undo button says it will undo.
 * Pass `replacesLog` for a change that swaps the history out rather than adding to it.
 */
const change = (label: string, mutate: () => void, options: { replacesLog?: boolean } = {}) => {
    pushUndo(undoStep(label, options));
    mutate();
};

const undo = () => {
    const last = undoStack.value.pop();
    if (!last) return;

    const state = JSON.parse(last.state) as Pick<Encounter, 'combatants' | 'round' | 'activeIndex'>;
    combatants.value = state.combatants;
    round.value = state.round;
    activeIndex.value = state.activeIndex;

    if (last.fullLog !== undefined) {
        log.value = JSON.parse(last.fullLog);
    } else {
        // Drop everything added since. If that entry has since been trimmed off the front,
        // everything left is newer than it, so it all goes.
        const index = last.lastLogId === null ? -1 : log.value.findIndex((e) => e.id === last.lastLogId);
        log.value.splice(index + 1);
    }
    if (!combatants.value.some((c) => c.id === selectedId.value)) selectedId.value = null;
};

// A saved encounter as the tracker would show it: a plain copy, brought up to date for older saves.
const loadable = (encounter: Encounter): Encounter => {
    const copy = plainCopy(encounter);
    normalizeCombatants(copy.combatants, creaturesById.value);
    copy.log = Array.isArray(copy.log) ? copy.log : [];
    return copy;
};

const resetView = () => {
    selectedId.value = null;
    amount.value = '';
    saveError.value = '';
    undoStack.value = [];
};

const startBlank = () => {
    encounterId.value = null;
    name.value = 'Untitled encounter';
    combatants.value = [];
    round.value = 0;
    activeIndex.value = 0;
    log.value = [];
    markSaved();
    resetView();
};

const openSaved = (encounter: Encounter) => {
    const copy = loadable(encounter);
    encounterId.value = copy.id;
    name.value = copy.name;
    combatants.value = copy.combatants;
    round.value = copy.round;
    activeIndex.value = copy.activeIndex < copy.combatants.length ? copy.activeIndex : 0;
    log.value = copy.log;
    markSaved();
    resetView();
};

// Only one saved encounter comes with the page, so opening another fetches it (just that prop).
const loadingEncounter = ref(false);
const loadEncounter = (id: number) => {
    if (props.openEncounter?.id === id) {
        openSaved(props.openEncounter);
        return;
    }

    router.get(
        route('encounters.index'),
        { encounter: id },
        {
            only: ['openEncounter'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                loadingEncounter.value = true;
            },
            onSuccess: () => {
                if (props.openEncounter?.id === id) openSaved(props.openEncounter);
                else saveError.value = "Couldn't open that encounter. It may have been deleted.";
            },
            onFinish: () => {
                loadingEncounter.value = false;
            },
        },
    );
};

const confirmDiscard = () => !isDirty.value || window.confirm('Discard your unsaved changes to this encounter?');

// Browser storage: the fight in progress survives a refresh for guests and signed-in users alike.
const storageKey = trackerStorageKey(user?.id ?? null);

const restoreTracker = (): boolean => {
    const stored = readTracker(storageKey);
    if (!stored) return false;

    // If the saved copy was deleted elsewhere, keep the work as an unsaved encounter.
    const saved = props.savedEncounters.find((e) => e.id === stored.encounterId);
    encounterId.value = saved ? saved.id : null;
    name.value = stored.name;
    combatants.value = normalizeCombatants(stored.combatants, creaturesById.value);
    round.value = stored.round;
    activeIndex.value = stored.activeIndex;
    log.value = stored.log;
    // An unsaved encounter (or one stored before fingerprints) always counts as having changes.
    savedFingerprint.value = saved ? (stored.savedFingerprint ?? '') : '';
    return true;
};

const persistTracker = () =>
    writeTracker(storageKey, {
        version: 2,
        encounterId: encounterId.value,
        name: name.value,
        combatants: combatants.value,
        round: round.value,
        activeIndex: activeIndex.value,
        log: log.value,
        savedFingerprint: savedFingerprint.value,
    });

// Switching encounters from the dropdown.
const switchTo = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    const target = props.savedEncounters.find((e) => String(e.id) === select.value);

    if (!target || !confirmDiscard()) {
        select.value = encounterId.value === null ? '' : String(encounterId.value);
        return;
    }
    loadEncounter(target.id);
};

const newEncounter = () => {
    const hasWork = isGuest ? combatants.value.length > 0 : isDirty.value;
    if (hasWork && !window.confirm(isGuest ? 'Clear this encounter and start a new one?' : 'Discard your unsaved changes to this encounter?')) return;
    startBlank();
};

const saveEncounter = () => {
    if (saving.value) return;

    const payload = {
        name: name.value.trim() || 'Untitled encounter',
        round: round.value,
        activeIndex: activeIndex.value,
        combatants: combatants.value,
        log: log.value,
    };
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            saving.value = true;
            saveError.value = '';
        },
        onSuccess: () => {
            encounterId.value = page.props.flash.savedEncounterId ?? encounterId.value;
            name.value = payload.name;
            markSaved();
        },
        onError: (errors: Record<string, string>) => {
            saveError.value = Object.values(errors)[0] ?? "Couldn't save this encounter.";
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (encounterId.value === null) {
        router.post(route('encounters.store'), payload, options);
    } else {
        router.put(route('encounters.update', encounterId.value), payload, options);
    }
};

const deleteEncounter = () => {
    const id = encounterId.value;
    if (id === null || !window.confirm(`Delete "${name.value}"? This can't be undone.`)) return;

    router.delete(route('encounters.destroy', id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: startBlank,
    });
};

const resetEncounter = () => {
    if (!window.confirm(`Reset "${name.value}" back to setup? Everyone goes back to full HP with no conditions, and the history is cleared.`)) return;

    change(
        'Reset',
        () => {
            combatants.value.forEach((combatant) => {
                combatant.hp = combatant.maxHp;
                combatant.conditions = [];
                combatant.used = {};
            });
            round.value = 0;
            activeIndex.value = 0;
            log.value = [];
        },
        { replacesLog: true },
    );
    selectedId.value = null;
};

// The order is the DM's: the tracker only sorts when initiative is rolled, combat starts, or they ask it to.
// Changing the order never moves the turn off whoever currently has it.
const keepingTurn = (reorder: () => void) => {
    const activeId = combatants.value[activeIndex.value]?.id;
    reorder();
    const index = combatants.value.findIndex((c) => c.id === activeId);
    activeIndex.value = index === -1 ? 0 : index;
};

const sortKeepingTurn = () => keepingTurn(() => combatants.value.sort(byInitiative));

const sortByInitiative = () =>
    change('Sort', () => {
        sortKeepingTurn();
        addLog({ type: 'sorted' });
    });

const rollAll = ({ includePlayers }: { includePlayers: boolean }) =>
    change('Roll initiative', () => {
        for (const combatant of combatants.value) {
            if (combatant.side === 'player' && !includePlayers) continue;
            combatant.initiative = rollInitiative(creatureOf(combatant));
        }
        sortKeepingTurn();
        addLog({ type: 'initiative_rolled', detail: includePlayers ? 'everyone' : undefined });
    });

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
        combatants.value.sort(byInitiative);
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

const addCombatants = (creature: Creature, count: number, initiative: number | null, side: CombatantSide) => {
    const added = combatantsFor(creature, count, initiative, combatants.value, side);
    change(`Add ${creature.name}`, () => {
        keepingTurn(() => insertByInitiative(combatants.value, added));
        if (!isSetup.value) {
            for (const combatant of added) addLog({ type: 'joined', targets: [combatant.name], amount: combatant.initiative });
        }
    });
    selectedId.value = added[0].id;
};

// Editing a number doesn't move anyone; "Sort" (or starting combat) does.
const setInitiative = (combatant: Combatant, event: Event) => {
    const value = Number.parseInt((event.target as HTMLInputElement).value, 10);
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

// Defeated non-players are skipped; players at 0 HP still get a turn (death saves, being revived).
const isOut = (combatant: Combatant) => combatant.hp <= 0 && combatant.side !== 'player';

/**
 * Changes HP and records going down, being defeated or getting back up. With `record`, the
 * damage or healing itself is logged too; actions log it as part of the action instead.
 */
const changeHp = (target: Combatant, delta: number, { record }: { record: boolean }) => {
    const before = target.hp;
    target.hp = Math.min(target.maxHp, Math.max(0, target.hp + delta));

    if (record && delta < 0) addLog({ type: 'damage', targets: [target.name], amount: -delta });
    if (record && delta > 0 && target.hp > before) addLog({ type: 'heal', targets: [target.name], amount: target.hp - before });

    if (before > 0 && target.hp === 0) addLog({ type: target.side === 'player' ? 'down' : 'defeated', targets: [target.name] });
    if (before === 0 && target.hp > 0) addLog({ type: 'revived', targets: [target.name] });
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

const applyHp = (direction: 1 | -1) => {
    const value = Number.parseInt(amount.value, 10);
    const target = selected.value;
    if (!target || Number.isNaN(value) || value <= 0) return;

    change(direction === -1 ? `Damage to ${target.name}` : `Healing for ${target.name}`, () => changeHp(target, direction * value, { record: true }));
    amount.value = '';
};

const onAmountKeydown = (event: KeyboardEvent) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    applyHp(event.shiftKey ? 1 : -1);
};

const toggleCondition = (combatant: Combatant, condition: string) => {
    const has = combatant.conditions.includes(condition);
    change(`${condition} on ${combatant.name}`, () => {
        combatant.conditions = has ? combatant.conditions.filter((c) => c !== condition) : [...combatant.conditions, condition];
        addLog({ type: has ? 'condition_off' : 'condition_on', targets: [combatant.name], detail: condition });
    });
};

// Using an action: the dialog picks targets and any damage or healing; this records it all as one step.
const actionOpen = ref(false);
const actionActorId = ref<string | null>(null);
const actionName = ref<string | null>(null);
const actionActor = computed(() => combatants.value.find((c) => c.id === actionActorId.value));
const chosenAction = computed(() =>
    actionActor.value ? creatureOf(actionActor.value)?.actions.find((a) => a.name === actionName.value) : undefined,
);

const openAction = (combatant: Combatant, action: CreatureAction) => {
    actionActorId.value = combatant.id;
    actionName.value = action.name;
    actionOpen.value = true;
};

const useAction = (targetIds: string[], effect: ActionEffect, value: number) => {
    const actor = actionActor.value;
    const action = chosenAction.value;
    if (!actor || !action) return;

    const targets = combatants.value.filter((c) => targetIds.includes(c.id));
    change(`${action.name}`, () => {
        if (action.uses) actor.used[action.name] = (actor.used[action.name] ?? 0) + 1;
        addLog({
            type: 'action',
            actor: actor.name,
            detail: action.name,
            targets: targets.length ? targets.map((t) => t.name) : undefined,
            amount: effect === 'none' ? undefined : value,
            effect: effect === 'none' ? undefined : effect,
        });
        if (effect !== 'none') {
            for (const target of targets) changeHp(target, effect === 'damage' ? -value : value, { record: false });
        }
    });
};

const hpPercent = (combatant: Combatant) => Math.round((combatant.hp / combatant.maxHp) * 100);

const hpBarColor = (combatant: Combatant) => {
    const percent = hpPercent(combatant);
    if (percent > 50) return 'bg-emerald-500';
    if (percent > 25) return 'bg-amber-500';
    return 'bg-red-500';
};

// Keyboard: N / P step turns, Alt+↑ / Alt+↓ move the selected combatant, Ctrl+Z (⌘Z) undoes.
// All ignored while typing or with a dialog open.
const onKeydown = (event: KeyboardEvent) => {
    const target = event.target as HTMLElement;
    if (addOpen.value || actionOpen.value || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName)) return;

    if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        undo();
        return;
    }
    if (event.metaKey || event.ctrlKey) return;

    if (event.altKey) {
        if (!selected.value || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;
        event.preventDefault();
        moveCombatant(selected.value, event.key === 'ArrowUp' ? -1 : 1);
        return;
    }

    if (event.key === 'n') step(1);
    if (event.key === 'p') step(-1);
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

// Pick up where this browser left off; otherwise open the most recent saved encounter, or a blank one.
if (!restoreTracker()) {
    if (props.openEncounter) openSaved(props.openEncounter);
    else startBlank();
}

watch([encounterId, name, combatants, round, activeIndex, log], persistTracker, { deep: true, immediate: true });
</script>

<template>
    <Head title="Encounters" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Encounter bar -->
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-card px-4 py-3">
                <select
                    v-if="!isGuest && (savedEncounters.length || encounterId === null)"
                    :value="encounterId === null ? '' : String(encounterId)"
                    :disabled="loadingEncounter"
                    class="h-9 max-w-48 rounded-md border border-input bg-background px-2 text-sm disabled:opacity-50"
                    aria-label="Open a saved encounter"
                    @change="switchTo"
                >
                    <option v-if="encounterId === null" value="">Unsaved encounter</option>
                    <option v-for="e in savedEncounters" :key="e.id" :value="String(e.id)">{{ e.name }}</option>
                </select>
                <Input v-model="name" class="h-9 w-56 font-medium" maxlength="100" aria-label="Encounter name" />
                <span v-if="!isGuest" class="text-xs" :class="isDirty ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
                    {{ isDirty ? 'Unsaved changes' : 'Saved' }}
                </span>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" @click="newEncounter">
                        <FilePlus2 />
                        New
                    </Button>
                    <Button v-if="isGuest" variant="outline" size="sm" as-child title="Create a free account to save encounters">
                        <Link :href="route('register')">
                            <Save />
                            Save
                        </Link>
                    </Button>
                    <Button v-else variant="outline" size="sm" :disabled="saving" @click="saveEncounter">
                        <Save />
                        {{ saving ? 'Saving…' : 'Save' }}
                    </Button>
                    <Button
                        v-if="!isGuest && encounterId !== null"
                        variant="outline"
                        size="sm"
                        class="text-red-600 dark:text-red-400"
                        @click="deleteEncounter"
                    >
                        <Trash2 />
                        Delete
                    </Button>
                    <Button variant="outline" size="sm" title="Coming soon">
                        <Download />
                        Export
                    </Button>
                </div>
            </div>

            <p v-if="saveError" class="text-sm text-red-600 dark:text-red-400">{{ saveError }}</p>

            <!-- Turn bar -->
            <div class="flex flex-wrap items-center gap-2">
                <Button size="sm" @click="addOpen = true">
                    <Plus />
                    Add combatant
                </Button>
                <span class="ml-2 text-sm text-muted-foreground">{{ isSetup ? 'Setting up' : `Round ${round}` }}</span>
                <Button
                    v-if="!isSetup"
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8"
                    title="Reset to setup"
                    aria-label="Reset to setup"
                    @click="resetEncounter"
                >
                    <RotateCcw />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="!lastUndo"
                    :title="lastUndo ? `Undo: ${lastUndo.label} (Ctrl+Z)` : 'Nothing to undo'"
                    @click="undo"
                >
                    <Undo2 />
                    Undo
                </Button>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" title="Coming soon">
                        <MonitorPlay />
                        Player view
                    </Button>

                    <template v-if="isSetup">
                        <div class="flex">
                            <Button
                                variant="outline"
                                size="sm"
                                class="rounded-r-none"
                                :disabled="combatants.length === 0"
                                title="Roll d20 + DEX for monsters and NPCs; players enter their own"
                                @click="rollAll({ includePlayers: false })"
                            >
                                <Dices />
                                Roll initiative
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="rounded-l-none border-l-0 px-2"
                                        :disabled="combatants.length === 0"
                                        aria-label="More ways to roll"
                                    >
                                        <ChevronDown />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @click="rollAll({ includePlayers: false })">Roll for monsters and NPCs</DropdownMenuItem>
                                    <DropdownMenuItem @click="rollAll({ includePlayers: true })">Roll for everyone, players too</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                        <Button size="sm" :disabled="combatants.length === 0" title="Sort by initiative and start round 1" @click="startCombat">
                            <Play />
                            Start combat
                        </Button>
                    </template>

                    <template v-else>
                        <Button variant="outline" size="sm" title="Sort the order by initiative" @click="sortByInitiative">
                            <ArrowDownWideNarrow />
                            Sort
                        </Button>
                        <Button variant="outline" size="sm" title="Previous turn (P)" @click="step(-1)">
                            <ArrowLeft />
                            Previous
                        </Button>
                        <Button size="sm" title="Next turn (N)" @click="step(1)">
                            Next turn
                            <ArrowRight />
                        </Button>
                    </template>
                </div>
            </div>

            <p v-if="isGuest" class="text-sm text-muted-foreground">
                You're using the tracker as a guest with the SRD monsters. Your fight is kept in this browser only, so export an encounter to keep it,
                or
                <Link :href="route('register')" class="font-medium text-foreground underline underline-offset-4">create a free account</Link>
                to save encounters and make your own creatures.
            </p>

            <!-- Empty state -->
            <div v-if="combatants.length === 0" class="rounded-lg border border-dashed border-border px-6 py-16 text-center">
                <h2 class="text-lg font-semibold">Build your encounter</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    Add monsters, NPCs and players from your compendium, roll initiative, then start combat and step through the turns.
                </p>
                <Button class="mt-4" @click="addOpen = true">
                    <Plus />
                    Add combatant
                </Button>
            </div>

            <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                <!-- Initiative order -->
                <div class="self-start overflow-hidden rounded-lg border border-border bg-card">
                    <div
                        class="grid grid-cols-[1.25rem_3.5rem_minmax(0,1fr)_8rem_3rem] gap-3 border-b border-border px-3 py-2 text-xs text-muted-foreground"
                    >
                        <!-- Holds the drag-handle column; an sr-only span would drop out of the grid. -->
                        <span aria-hidden="true" />
                        <span>Init</span>
                        <span>Name</span>
                        <span>HP</span>
                        <span class="text-right">AC</span>
                    </div>

                    <!-- Drag by the handle to reorder; Alt+↑/↓ moves the selected combatant. -->
                    <VueDraggable
                        v-model="combatants"
                        handle=".drag-handle"
                        :animation="150"
                        ghost-class="opacity-40"
                        @start="onDragStart"
                        @end="onDragEnd"
                    >
                        <div
                            v-for="(combatant, index) in combatants"
                            :key="combatant.id"
                            data-combatant-row
                            class="grid w-full cursor-pointer grid-cols-[1.25rem_3.5rem_minmax(0,1fr)_8rem_3rem] items-center gap-3 border-b border-l-4 border-b-border py-3 pl-2 pr-3 text-left text-sm transition-colors last:border-b-0 hover:bg-accent"
                            :class="[
                                !isSetup && index === activeIndex ? 'border-l-primary bg-accent/60' : 'border-l-transparent',
                                selected?.id === combatant.id && (isSetup || index !== activeIndex) ? 'bg-accent/40' : '',
                                isOut(combatant) ? 'text-muted-foreground' : '',
                            ]"
                            @click="selectedId = combatant.id"
                        >
                            <button
                                type="button"
                                class="drag-handle flex cursor-grab touch-none items-center justify-center rounded text-muted-foreground hover:text-foreground active:cursor-grabbing"
                                :aria-label="`Reorder ${combatant.name} (drag, or select and press Alt+Up or Alt+Down)`"
                                @click.stop="selectedId = combatant.id"
                            >
                                <GripVertical class="size-4" />
                            </button>
                            <Input
                                v-if="isSetup"
                                :model-value="combatant.initiative"
                                type="number"
                                class="h-8 w-14 px-2 text-center tabular-nums"
                                :aria-label="`Initiative for ${combatant.name}`"
                                @click.stop="selectedId = combatant.id"
                                @change="setInitiative(combatant, $event)"
                            />
                            <span v-else class="font-medium tabular-nums">{{ combatant.initiative }}</span>
                            <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                                <span
                                    class="size-2 shrink-0 rounded-full"
                                    :class="sideInfo(combatant.side).dot"
                                    :title="sideInfo(combatant.side).label"
                                    aria-hidden="true"
                                />
                                <button
                                    type="button"
                                    class="truncate text-left font-medium"
                                    :class="isOut(combatant) ? 'line-through' : ''"
                                    @click.stop="selectedId = combatant.id"
                                >
                                    {{ combatant.name }}
                                </button>
                                <span class="sr-only">({{ sideInfo(combatant.side).label }})</span>
                                <span v-if="combatant.side !== 'enemy'" class="text-xs" :class="sideInfo(combatant.side).text">
                                    {{ sideInfo(combatant.side).label.toLowerCase() }}
                                </span>
                                <span v-if="combatant.conditions.length" class="flex items-center gap-0.5 text-red-600 dark:text-red-400">
                                    <span
                                        v-for="condition in combatant.conditions"
                                        :key="condition"
                                        :title="condition"
                                        role="img"
                                        :aria-label="condition"
                                    >
                                        <component
                                            :is="conditionIcon(condition)"
                                            v-if="conditionIcon(condition)"
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        <span v-else class="text-xs" aria-hidden="true">{{ condition }}</span>
                                    </span>
                                </span>
                            </span>
                            <span class="space-y-1">
                                <span class="block tabular-nums">{{ combatant.hp }} / {{ combatant.maxHp }}</span>
                                <span class="block h-1.5 overflow-hidden rounded-full bg-muted">
                                    <span
                                        class="block h-full rounded-full"
                                        :class="hpBarColor(combatant)"
                                        :style="{ width: `${hpPercent(combatant)}%` }"
                                    />
                                </span>
                            </span>
                            <span class="text-right tabular-nums">{{ combatant.ac }}</span>
                        </div>
                    </VueDraggable>
                </div>

                <!-- Right panel: the selected combatant, or the combat history -->
                <div class="self-start rounded-lg border border-border bg-card">
                    <div class="flex border-b border-border" role="tablist" aria-label="Panel">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="panelTab === 'combatant'"
                            class="flex-1 border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                            :class="panelTab === 'combatant' ? 'border-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                            @click="panelTab = 'combatant'"
                        >
                            {{ selected?.name ?? 'Combatant' }}
                        </button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="panelTab === 'history'"
                            class="flex-1 border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                            :class="panelTab === 'history' ? 'border-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                            @click="panelTab = 'history'"
                        >
                            History
                            <span v-if="log.length" class="ml-1 text-xs text-muted-foreground">({{ log.length }})</span>
                        </button>
                    </div>

                    <div v-if="panelTab === 'history'" class="max-h-[70vh] overflow-y-auto p-4">
                        <CombatHistory :log="log" />
                    </div>

                    <div v-else-if="selected" class="space-y-4 p-4">
                        <div class="flex items-baseline justify-between gap-2">
                            <h2 class="text-lg font-semibold">{{ selected.name }}</h2>
                            <span class="text-xs text-muted-foreground">{{ selected.id === active?.id ? 'Active turn' : 'Selected' }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <span class="mr-2 text-sm tabular-nums">
                                <span class="text-muted-foreground">HP</span>
                                <span class="ml-1 font-medium">{{ selected.hp }} / {{ selected.maxHp }}</span>
                            </span>
                            <Input
                                v-model="amount"
                                type="number"
                                min="1"
                                placeholder="0"
                                class="h-8 w-20"
                                aria-label="Damage or healing amount"
                                @keydown="onAmountKeydown"
                            />
                            <Button variant="outline" size="sm" class="text-red-600 dark:text-red-400" title="Enter" @click="applyHp(-1)"
                                >Damage</Button
                            >
                            <Button
                                variant="outline"
                                size="sm"
                                class="text-emerald-600 dark:text-emerald-400"
                                title="Shift + Enter"
                                @click="applyHp(1)"
                            >
                                Heal
                            </Button>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <label class="flex items-center gap-2 text-sm">
                                <span class="text-muted-foreground">Initiative</span>
                                <Input
                                    :key="selected.id"
                                    :model-value="selected.initiative"
                                    type="number"
                                    class="h-8 w-20"
                                    @change="setInitiative(selected, $event)"
                                />
                            </label>
                            <Button variant="ghost" size="sm" class="ml-auto text-red-600 dark:text-red-400" @click="removeCombatant(selected)">
                                <Trash2 />
                                Remove
                            </Button>
                        </div>

                        <!-- Side: players are fixed, everyone else can switch mid-fight -->
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Side</span>
                            <span v-if="selected.side === 'player'" class="inline-flex items-center gap-1.5">
                                <span class="size-2 rounded-full" :class="sideInfo('player').dot" aria-hidden="true" />
                                Player character
                            </span>
                            <div v-else class="flex rounded-md border border-border p-0.5" role="group" aria-label="Side">
                                <button
                                    v-for="option in switchableSides"
                                    :key="option.value"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs transition-colors"
                                    :class="
                                        selected.side === option.value
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:bg-accent'
                                    "
                                    :aria-pressed="selected.side === option.value"
                                    @click="setSide(selected, option.value)"
                                >
                                    <span class="size-2 rounded-full" :class="option.dot" aria-hidden="true" />
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Conditions: one toggle per condition -->
                        <div class="space-y-2">
                            <p class="text-sm">
                                <span class="text-muted-foreground">Conditions</span>
                                <span v-if="selected.conditions.length" class="ml-1 font-medium">{{ selected.conditions.join(', ') }}</span>
                            </p>
                            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Conditions">
                                <button
                                    v-for="condition in conditions"
                                    :key="condition.name"
                                    type="button"
                                    class="inline-flex size-9 items-center justify-center rounded-md border transition-colors"
                                    :class="
                                        selected.conditions.includes(condition.name)
                                            ? 'border-red-500 bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                                            : 'border-border text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                                    "
                                    :title="condition.name"
                                    :aria-label="condition.name"
                                    :aria-pressed="selected.conditions.includes(condition.name)"
                                    @click="toggleCondition(selected, condition.name)"
                                >
                                    <component :is="condition.icon" class="size-4" />
                                </button>
                            </div>
                        </div>

                        <!-- Actions: use one to record it (and any damage or healing) in the history -->
                        <section v-if="selectedCreature?.actions.length" class="space-y-2 border-t border-border pt-4">
                            <div class="flex items-baseline justify-between">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</h3>
                                <span v-if="isSetup" class="text-xs text-muted-foreground">Start combat to use actions</span>
                            </div>
                            <div
                                v-for="action in selectedCreature.actions"
                                :key="action.name"
                                class="flex items-start gap-3 rounded-md border border-border p-2.5"
                            >
                                <div class="min-w-0 flex-1 text-sm">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="font-medium">{{ action.name }}</span>
                                        <template v-if="action.uses">
                                            <span
                                                v-if="action.uses <= 10"
                                                class="flex gap-0.5"
                                                role="img"
                                                :aria-label="`${usesLeft(selected, action)} of ${action.uses} uses left`"
                                            >
                                                <span
                                                    v-for="n in action.uses"
                                                    :key="n"
                                                    class="size-2 rounded-full border border-primary"
                                                    :class="n <= (usesLeft(selected, action) ?? 0) ? 'bg-primary' : ''"
                                                />
                                            </span>
                                            <span class="text-xs text-muted-foreground">
                                                {{ usesLeft(selected, action) }}/{{ action.uses }} left · {{ limitLabel(action) }}
                                            </span>
                                        </template>
                                    </p>
                                    <p class="mt-0.5 line-clamp-2 text-muted-foreground" :title="action.description">{{ action.description }}</p>
                                </div>
                                <Button v-if="!isSetup" variant="outline" size="sm" @click="openAction(selected, action)">Use</Button>
                            </div>
                        </section>

                        <StatBlock v-if="selectedCreature" :creature="selectedCreature" hide-actions class="border-t border-border pt-4" />
                        <p v-else class="border-t border-border pt-4 text-sm text-muted-foreground">
                            This creature is no longer in your compendium, so its stat block isn't available.
                        </p>
                    </div>
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                Shortcuts: <kbd class="rounded border px-1">N</kbd> next turn, <kbd class="rounded border px-1">P</kbd> previous turn,
                <kbd class="rounded border px-1">Enter</kbd> damage, <kbd class="rounded border px-1">Shift + Enter</kbd> heal,
                <kbd class="rounded border px-1">Ctrl + Z</kbd> undo.
            </p>
        </div>

        <AddCombatantDialog v-model:open="addOpen" :creatures="creatures" @add="addCombatants" />
        <UseActionDialog
            v-model:open="actionOpen"
            :actor="actionActor"
            :action="chosenAction"
            :uses-left="actionActor && chosenAction ? usesLeft(actionActor, chosenAction) : null"
            :combatants="combatants"
            @use="useAction"
        />
    </AppLayout>
</template>
