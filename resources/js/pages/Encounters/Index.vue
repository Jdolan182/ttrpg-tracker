<script setup lang="ts">
import AddCombatantDialog from '@/components/AddCombatantDialog.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import CombatHistory from '@/components/CombatHistory.vue';
import GroupDamageDialog from '@/components/GroupDamageDialog.vue';
import StatBlock from '@/components/StatBlock.vue';
import StatGrid from '@/components/StatGrid.vue';
import StatsEditor from '@/components/StatsEditor.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import UseActionDialog from '@/components/UseActionDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { logEntry, MAX_LOG_ENTRIES, type LogEntry } from '@/lib/combatLog';
import {
    byInitiative,
    combatantsFor,
    concentrationDc,
    conditionDurations,
    conditionIcon,
    conditions,
    defaultStatLabels,
    insertByInitiative,
    isDead,
    isStable,
    limitLabel,
    normalizeCombatants,
    quickCombatant,
    restoreUses,
    rollInitiative,
    sideInfo,
    switchableSides,
    usesLeft,
    type ActionEffect,
    type QuickCombatantDetails,
} from '@/lib/encounter';
import { rowsFromStats, statRows, statsFromRows, type StatRow } from '@/lib/stats';
import { readTracker, trackerStorageKey, writeTracker } from '@/lib/trackerStorage';
import { fingerprint, plainCopy } from '@/lib/utils';
import type { SharedData } from '@/types';
import type { Combatant, CombatantSide, Creature, CreatureAction, Encounter, EncounterSummary, TrackerCampaign } from '@/types/tracker';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDownWideNarrow,
    ArrowLeft,
    ArrowRight,
    Brain,
    ChevronDown,
    Dices,
    Download,
    EyeClosed,
    FilePlus2,
    Flame,
    GripVertical,
    HeartPulse,
    MonitorPlay,
    Pencil,
    Play,
    Plus,
    RotateCcw,
    Save,
    ShieldPlus,
    Skull,
    Trash2,
    Undo2,
    UsersRound,
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
    // Campaigns the user runs, for the campaign picker and "Add party".
    campaigns: TrackerCampaign[];
    // Set when arriving from a campaign's "New encounter" button.
    newInCampaign: number | null;
}>();

const page = usePage<SharedData>();
const user = page.props.auth.user;
const isGuest = !user;

const encounterId = ref<number | null>(null);
const name = ref('');
// The campaign this encounter belongs to, if any.
const campaignId = ref<number | null>(null);
const campaign = computed(() => props.campaigns.find((c) => c.id === campaignId.value));
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
// Quick-added combatants have no creature, so no stat block or actions.
const creatureOf = (combatant: Combatant) => (combatant.creatureId === null ? undefined : creaturesById.value.get(combatant.creatureId));
const selectedCreature = computed(() => (selected.value ? creatureOf(selected.value) : undefined));
// A creature's stats, or for a quick-added combatant the ones typed in for it.
const statsOf = (combatant: Combatant) => creatureOf(combatant)?.stats ?? combatant.stats;

// Unsaved-changes tracking: compare a fingerprint of the current fight with one taken when it was
// last saved or opened. A fingerprint rather than a copy, so it can be kept in browser storage too.
const currentFingerprint = () =>
    fingerprint(
        JSON.stringify({
            name: name.value,
            // Left out when empty (undefined), so fingerprints stored before campaigns existed still match.
            campaignId: campaignId.value ?? undefined,
            round: round.value,
            activeIndex: activeIndex.value,
            combatants: combatants.value,
            log: log.value,
        }),
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
    // Prompts for saves that may no longer apply; the history shows what was resolved.
    concentrationChecks.value = [];
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

// Drops ?encounter= / ?new_in_campaign= once they've been acted on, so a later refresh doesn't
// act on them again (e.g. reopening an encounter after the DM has moved on to a new one).
// (Only once mounted: on page load, onMounted does it after Inertia has set up the page.)
let mounted = false;
const clearUrlQuery = () => {
    if (!mounted) return;
    // A client-side visit, so Inertia's own copy of the URL changes too and doesn't put the query back.
    if (window.location.search) router.replace({ url: route('encounters.index', undefined, false), preserveState: true, preserveScroll: true });
};

const startBlank = (inCampaign: number | null = null) => {
    clearUrlQuery();
    encounterId.value = null;
    name.value = 'Untitled encounter';
    campaignId.value = inCampaign;
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
    campaignId.value = copy.campaignId ?? null;
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
    // Drop a campaign that's since been deleted (or belongs to someone else after logging out).
    campaignId.value = props.campaigns.some((c) => c.id === stored.campaignId) ? (stored.campaignId ?? null) : null;
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
        campaignId: campaignId.value,
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
        campaignId: campaignId.value,
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
        onSuccess: () => startBlank(),
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
                // Fight state goes; who's hidden is set-up, so it stays.
                delete combatant.durations;
                delete combatant.tempHp;
                delete combatant.concentrating;
                delete combatant.deathSaves;
            });
            concentrationChecks.value = [];
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
            combatant.initiative = rollInitiative(statsOf(combatant));
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

// The campaign's party members who aren't in the fight yet.
const missingParty = computed(() => {
    if (!campaign.value) return [];
    const present = new Set(combatants.value.map((c) => c.creatureId));
    return campaign.value.partyIds
        .filter((id) => !present.has(id))
        .map((id) => creaturesById.value.get(id))
        .filter((creature): creature is Creature => !!creature);
});

const addParty = () => {
    const party = missingParty.value;
    if (!party.length) return;
    change('Add party', () => {
        for (const creature of party) {
            const added = combatantsFor(creature, 1, null, combatants.value, 'player');
            keepingTurn(() => insertByInitiative(combatants.value, added));
            if (!isSetup.value) addLog({ type: 'joined', targets: [added[0].name], amount: added[0].initiative });
        }
    });
};

// Editing a quick-added combatant's own stats. Undoable, but not worth a history entry.
const editingStats = ref(false);
const statDraft = ref<StatRow[]>([]);
const startEditingStats = (combatant: Combatant) => {
    statDraft.value = combatant.stats?.length ? rowsFromStats(combatant.stats) : statRows(defaultStatLabels);
    editingStats.value = true;
};
const saveStats = (combatant: Combatant) => {
    const stats = statsFromRows(statDraft.value);
    change(`Stats for ${combatant.name}`, () => {
        if (stats.length) combatant.stats = stats;
        else delete combatant.stats;
    });
    editingStats.value = false;
};
// Close the editor when a different combatant is picked, so it never edits the wrong one.
watch(
    () => selected.value?.id,
    () => {
        editingStats.value = false;
    },
);

const quickAdd = (details: QuickCombatantDetails) => {
    const combatant = quickCombatant(details);
    change(`Add ${combatant.name}`, () => {
        keepingTurn(() => insertByInitiative(combatants.value, [combatant]));
        if (!isSetup.value) addLog({ type: 'joined', targets: [combatant.name], amount: combatant.initiative });
    });
    selectedId.value = combatant.id;
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

// Out of the fight and skipped in the turn order: defeated non-players, and players who have died.
// Players who are down but not dead still get their turn (death saves, being revived).
const isOut = (combatant: Combatant) => (combatant.hp <= 0 && combatant.side !== 'player') || isDead(combatant);

// Concentration saves waiting on the DM, one per hit taken while concentrating. Not part of the
// fight's saved state: they're prompts, and the outcome is what goes in the history.
const concentrationChecks = ref<{ key: string; combatantId: string; dc: number }[]>([]);
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

const recordDeathSave = (target: Combatant, kind: 'success' | 'failure') => change(`Death save for ${target.name}`, () => addDeathSave(target, kind));

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

// Temporary HP doesn't stack: gaining some keeps whichever is higher.
const giveTempHp = (target: Combatant, value: number) => {
    if (value <= (target.tempHp ?? 0)) return;
    change(`Temp HP for ${target.name}`, () => {
        target.tempHp = value;
        addLog({ type: 'temp_hp', targets: [target.name], amount: value });
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

const resolveConcentration = (check: { key: string; combatantId: string; dc: number }, kept: boolean) => {
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

// Damage or heal several at once (area effects); each target can take half for a successful save.
const groupOpen = ref(false);
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

const roundsLeft = (rounds: number) => (rounds === 1 ? '1 round left' : `${rounds} rounds left`);

const setConditionDuration = (target: Combatant, condition: string, rounds: number | null) =>
    change(`${condition} duration`, () => {
        if (rounds === null) {
            if (target.durations) delete target.durations[condition];
        } else {
            target.durations = { ...(target.durations ?? {}), [condition]: rounds };
        }
        if (target.durations && !Object.keys(target.durations).length) delete target.durations;
    });

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

const applyHp = (direction: 1 | -1) => {
    const value = Number.parseInt(amount.value, 10);
    const target = selected.value;
    if (!target || Number.isNaN(value) || value <= 0) return;

    change(direction === -1 ? `Damage to ${target.name}` : `Healing for ${target.name}`, () => changeHp(target, direction * value, { record: true }));
    amount.value = '';
};

const applyTempHp = () => {
    const value = Number.parseInt(amount.value, 10);
    const target = selected.value;
    if (!target || Number.isNaN(value) || value <= 0) return;

    giveTempHp(target, Math.min(value, 100000));
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
        if (has && combatant.durations) {
            delete combatant.durations[condition];
            if (!Object.keys(combatant.durations).length) delete combatant.durations;
        }
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
    if (addOpen.value || actionOpen.value || groupOpen.value || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName)) return;

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

// Pick up where this browser left off, unless a link asked for something else: a campaign's
// "New encounter" button, or a specific encounter (?encounter=). Never lose unsaved work silently.
const restored = restoreTracker();
const requestedId = Number(new URLSearchParams(window.location.search).get('encounter')) || null;
const okToReplace = () =>
    !restored || !isDirty.value || !combatants.value.length || window.confirm('Discard your unsaved changes to this encounter?');

if (props.newInCampaign) {
    if (okToReplace()) startBlank(props.newInCampaign);
} else if (requestedId && props.openEncounter?.id === requestedId && encounterId.value !== requestedId) {
    if (okToReplace()) openSaved(props.openEncounter);
} else if (!restored) {
    if (props.openEncounter) openSaved(props.openEncounter);
    else startBlank();
}
onMounted(() => {
    mounted = true;
    clearUrlQuery();
});

watch([encounterId, name, campaignId, combatants, round, activeIndex, log], persistTracker, { deep: true, immediate: true });
</script>

<template>
    <Head title="Encounters" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Encounter bar -->
            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card px-4 py-3 shadow-sm">
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
                <!-- Only matters for a new encounter: saving an existing one never counts against the limit -->
                <span
                    v-if="encounterId === null && page.props.limits"
                    class="text-xs tabular-nums"
                    :class="
                        page.props.limits.encounters.used >= page.props.limits.encounters.limit
                            ? 'font-medium text-amber-700 dark:text-amber-400'
                            : 'text-muted-foreground'
                    "
                >
                    · {{ page.props.limits.encounters.used }} of {{ page.props.limits.encounters.limit }} encounters saved
                </span>
                <select
                    v-if="!isGuest && (campaigns.length || campaignId !== null)"
                    v-model="campaignId"
                    class="h-9 max-w-48 rounded-md border border-input bg-background px-2 text-sm"
                    aria-label="Campaign"
                >
                    <option :value="null">No campaign</option>
                    <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>

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
                <Button
                    v-if="missingParty.length"
                    variant="outline"
                    size="sm"
                    :title="`Add ${missingParty.map((c) => c.name).join(', ')}`"
                    @click="addParty"
                >
                    <UsersRound />
                    Add party
                </Button>
                <!-- Where the fight is at, readable from across the table -->
                <span v-if="isSetup" class="ml-2 rounded-full border border-dashed border-border px-3 py-1 text-sm text-muted-foreground">
                    Setting up
                </span>
                <span v-else class="ml-2 inline-flex items-center gap-2 rounded-full bg-primary/10 py-1 pl-1 pr-3 text-sm">
                    <span class="rounded-full bg-primary px-2.5 py-0.5 font-display font-semibold text-primary-foreground">Round {{ round }}</span>
                    <template v-if="active">
                        <span class="size-2 rounded-full" :class="sideInfo(active.side).dot" aria-hidden="true" />
                        <span class="font-medium">{{ active.name }}'s turn</span>
                    </template>
                </span>
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
                        <Button variant="outline" size="sm" title="Damage or heal several at once, e.g. a fireball" @click="groupOpen = true">
                            <Flame />
                            Damage several
                        </Button>
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

            <!-- Concentration saves to resolve, one per hit on someone concentrating -->
            <div
                v-for="check in pendingChecks"
                :key="check.key"
                class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-100"
                role="alert"
            >
                <Brain class="size-4 shrink-0" />
                <span class="flex-1">
                    <span class="font-medium">{{ check.combatant?.name }}</span> took damage while concentrating: Constitution save, DC
                    <span class="font-semibold tabular-nums">{{ check.dc }}</span
                    >.
                </span>
                <Button size="sm" variant="outline" class="h-7 bg-background" @click="resolveConcentration(check, true)">Kept it</Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-7 bg-background text-red-600 dark:text-red-400"
                    @click="resolveConcentration(check, false)"
                >
                    Lost it
                </Button>
            </div>

            <!-- Empty state -->
            <div v-if="combatants.length === 0" class="rounded-xl border border-dashed border-border bg-card/60 px-6 py-16 text-center">
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <AppLogoIcon class="size-7" />
                </div>
                <h2 class="text-2xl font-semibold">Build your encounter</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                    Add monsters, NPCs and players from your compendium, roll initiative, then start combat and step through the turns.
                </p>
                <Button class="mt-4" @click="addOpen = true">
                    <Plus />
                    Add combatant
                </Button>
            </div>

            <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                <!-- Initiative order -->
                <div class="self-start overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <div
                        class="grid grid-cols-[1.25rem_3.5rem_minmax(0,1fr)_8rem_3rem] gap-3 border-b border-border bg-muted/60 px-3 py-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"
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
                                !isSetup && index === activeIndex ? 'border-l-primary bg-primary/[0.07]' : 'border-l-transparent',
                                selected?.id === combatant.id && (isSetup || index !== activeIndex) ? 'bg-accent/70' : '',
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
                                    :class="[isOut(combatant) ? 'line-through' : '', combatant.hidden ? 'italic opacity-70' : '']"
                                    @click.stop="selectedId = combatant.id"
                                >
                                    {{ combatant.name }}
                                </button>
                                <span class="sr-only">({{ sideInfo(combatant.side).label }})</span>
                                <span v-if="combatant.side !== 'enemy'" class="text-xs" :class="sideInfo(combatant.side).text">
                                    {{ sideInfo(combatant.side).label.toLowerCase() }}
                                </span>
                                <span v-if="combatant.hidden" title="Hidden from players" role="img" aria-label="Hidden from players">
                                    <EyeClosed class="size-3.5 text-muted-foreground" aria-hidden="true" />
                                </span>
                                <span v-if="combatant.concentrating" title="Concentrating" role="img" aria-label="Concentrating">
                                    <Brain class="size-3.5 text-violet-600 dark:text-violet-400" aria-hidden="true" />
                                </span>
                                <span
                                    v-if="isDead(combatant)"
                                    class="inline-flex items-center gap-0.5 text-xs font-medium text-red-700 dark:text-red-400"
                                >
                                    <Skull class="size-3.5" aria-hidden="true" />
                                    dead
                                </span>
                                <span
                                    v-else-if="combatant.side === 'player' && combatant.hp === 0"
                                    class="text-xs tabular-nums"
                                    :title="`Death saves: ${combatant.deathSaves?.successes ?? 0} successes, ${combatant.deathSaves?.failures ?? 0} failures`"
                                >
                                    <template v-if="isStable(combatant)"><span class="text-emerald-700 dark:text-emerald-400">stable</span></template>
                                    <template v-else>
                                        <span class="text-emerald-700 dark:text-emerald-400">✓{{ combatant.deathSaves?.successes ?? 0 }}</span>
                                        <span class="ml-1 text-red-700 dark:text-red-400">✗{{ combatant.deathSaves?.failures ?? 0 }}</span>
                                    </template>
                                </span>
                                <span v-if="combatant.conditions.length" class="flex items-center gap-1 text-red-600 dark:text-red-400">
                                    <span
                                        v-for="condition in combatant.conditions"
                                        :key="condition"
                                        class="inline-flex items-center"
                                        :title="
                                            combatant.durations?.[condition]
                                                ? `${condition} (${roundsLeft(combatant.durations[condition])})`
                                                : condition
                                        "
                                        role="img"
                                        :aria-label="
                                            combatant.durations?.[condition]
                                                ? `${condition}, ${roundsLeft(combatant.durations[condition])}`
                                                : condition
                                        "
                                    >
                                        <component
                                            :is="conditionIcon(condition)"
                                            v-if="conditionIcon(condition)"
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        <span v-else class="text-xs" aria-hidden="true">{{ condition }}</span>
                                        <sub v-if="combatant.durations?.[condition]" class="text-[10px] font-medium tabular-nums" aria-hidden="true">
                                            {{ combatant.durations[condition] }}
                                        </sub>
                                    </span>
                                </span>
                            </span>
                            <span class="space-y-1">
                                <span class="block tabular-nums">
                                    {{ combatant.hp }} / {{ combatant.maxHp }}
                                    <span v-if="combatant.tempHp" class="text-xs font-medium text-sky-700 dark:text-sky-400" title="Temporary HP">
                                        +{{ combatant.tempHp }}
                                    </span>
                                </span>
                                <span class="block h-2 overflow-hidden rounded-full bg-muted">
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
                <div class="self-start overflow-hidden rounded-xl border border-border bg-card shadow-sm">
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
                                <span
                                    v-if="selected.tempHp"
                                    class="ml-1 font-medium text-sky-700 dark:text-sky-400"
                                    title="Temporary HP, used up first"
                                >
                                    +{{ selected.tempHp }} temp
                                </span>
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
                            <Button
                                variant="outline"
                                size="sm"
                                class="text-sky-700 dark:text-sky-400"
                                title="Give temporary HP (keeps whichever is higher)"
                                @click="applyTempHp"
                            >
                                <ShieldPlus />
                                Temp HP
                            </Button>
                        </div>

                        <!-- Death saves, for a player character who's down -->
                        <div
                            v-if="selected.side === 'player' && selected.hp === 0"
                            class="rounded-lg border border-border bg-muted/50 p-3 text-sm"
                            role="group"
                            aria-label="Death saves"
                        >
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <span class="inline-flex items-center gap-1.5 font-medium">
                                    <HeartPulse class="size-4 text-red-600 dark:text-red-400" />
                                    Death saves
                                </span>
                                <span v-if="isDead(selected)" class="font-medium text-red-700 dark:text-red-400">Dead</span>
                                <span v-else-if="isStable(selected)" class="font-medium text-emerald-700 dark:text-emerald-400">Stable</span>
                                <span v-else class="text-xs text-muted-foreground">Heal them to bring them back up</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                                <div class="flex items-center gap-2">
                                    <span class="w-16 text-muted-foreground">Successes</span>
                                    <span class="flex gap-1" aria-hidden="true">
                                        <span
                                            v-for="n in 3"
                                            :key="n"
                                            class="size-3.5 rounded-full border border-emerald-600"
                                            :class="n <= (selected.deathSaves?.successes ?? 0) ? 'bg-emerald-600' : ''"
                                        />
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="h-7 px-2 text-xs"
                                        :disabled="isDead(selected) || (selected.deathSaves?.successes ?? 0) >= 3"
                                        @click="recordDeathSave(selected, 'success')"
                                    >
                                        + Success
                                    </Button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-16 text-muted-foreground">Failures</span>
                                    <span class="flex gap-1" aria-hidden="true">
                                        <span
                                            v-for="n in 3"
                                            :key="n"
                                            class="size-3.5 rounded-full border border-red-600"
                                            :class="n <= (selected.deathSaves?.failures ?? 0) ? 'bg-red-600' : ''"
                                        />
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="h-7 px-2 text-xs"
                                        :disabled="isDead(selected) || isStable(selected)"
                                        @click="recordDeathSave(selected, 'failure')"
                                    >
                                        + Failure
                                    </Button>
                                </div>
                            </div>
                            <p class="sr-only">
                                {{ selected.deathSaves?.successes ?? 0 }} successes and {{ selected.deathSaves?.failures ?? 0 }} failures.
                            </p>
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

                        <!-- On/off states: concentrating on a spell, hidden from the players -->
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs transition-colors"
                                :class="
                                    selected.concentrating
                                        ? 'border-violet-500 bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200'
                                        : 'border-border text-muted-foreground hover:bg-accent'
                                "
                                :aria-pressed="!!selected.concentrating"
                                title="Concentrating on a spell: damage prompts a Constitution save"
                                @click="toggleConcentration(selected)"
                            >
                                <Brain class="size-3.5" />
                                Concentrating
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs transition-colors"
                                :class="
                                    selected.hidden
                                        ? 'border-foreground/40 bg-muted text-foreground'
                                        : 'border-border text-muted-foreground hover:bg-accent'
                                "
                                :aria-pressed="!!selected.hidden"
                                title="Hidden from players: they won't see this combatant in the player view"
                                @click="toggleHidden(selected)"
                            >
                                <EyeClosed class="size-3.5" />
                                Hidden from players
                            </button>
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
                            <p class="text-sm text-muted-foreground">Conditions</p>
                            <!-- Active ones, each with how long it lasts -->
                            <ul v-if="selected.conditions.length" class="flex flex-wrap gap-1.5">
                                <li
                                    v-for="condition in selected.conditions"
                                    :key="condition"
                                    class="inline-flex items-center gap-1 rounded-md border border-red-300 bg-red-50 py-0.5 pl-2 pr-1 text-xs text-red-800 dark:border-red-900 dark:bg-red-950/60 dark:text-red-200"
                                >
                                    <component :is="conditionIcon(condition)" v-if="conditionIcon(condition)" class="size-3.5" aria-hidden="true" />
                                    <span class="font-medium">{{ condition }}</span>
                                    <select
                                        :value="selected.durations?.[condition] ?? ''"
                                        class="h-6 rounded border-0 bg-transparent py-0 pl-1 pr-6 text-xs focus:ring-1 focus:ring-red-400"
                                        :aria-label="`How long ${condition} lasts`"
                                        @change="
                                            setConditionDuration(
                                                selected,
                                                condition,
                                                ($event.target as HTMLSelectElement).value === ''
                                                    ? null
                                                    : Number(($event.target as HTMLSelectElement).value),
                                            )
                                        "
                                    >
                                        <option v-for="option in conditionDurations" :key="option.label" :value="option.value ?? ''">
                                            {{ option.label }}
                                        </option>
                                        <!-- A count that's ticked down to something not in the list still shows correctly -->
                                        <option
                                            v-if="
                                                selected.durations?.[condition] &&
                                                !conditionDurations.some((o) => o.value === selected.durations?.[condition])
                                            "
                                            :value="selected.durations[condition]"
                                        >
                                            {{ roundsLeft(selected.durations[condition]) }}
                                        </option>
                                    </select>
                                </li>
                            </ul>
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
                        <!-- Quick-added: its own stats, editable, since there's no compendium entry behind it -->
                        <section v-else-if="selected.creatureId === null" class="space-y-3 border-t border-border pt-4">
                            <div class="flex items-baseline justify-between gap-2">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Stats</h3>
                                <Button v-if="!editingStats" variant="ghost" size="sm" class="h-7 px-2 text-xs" @click="startEditingStats(selected)">
                                    <Pencil />
                                    {{ selected.stats?.length ? 'Edit stats' : 'Add stats' }}
                                </Button>
                            </div>
                            <template v-if="editingStats">
                                <StatsEditor v-model="statDraft" />
                                <div class="flex justify-end gap-2">
                                    <Button variant="outline" size="sm" @click="editingStats = false">Cancel</Button>
                                    <Button size="sm" @click="saveStats(selected)">Save stats</Button>
                                </div>
                            </template>
                            <StatGrid v-else-if="selected.stats?.length" :stats="selected.stats" />
                            <p v-else class="text-sm text-muted-foreground">
                                No stats yet. Add some to see their modifiers, and DEX will count when you roll initiative.
                            </p>
                        </section>
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

        <AddCombatantDialog v-model:open="addOpen" :creatures="creatures" @add="addCombatants" @quick-add="quickAdd" />
        <GroupDamageDialog v-model:open="groupOpen" :combatants="combatants" :preselected="selectedId ? [selectedId] : []" @apply="applyGroup" />
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
