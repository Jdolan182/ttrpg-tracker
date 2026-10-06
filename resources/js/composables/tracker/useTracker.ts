import { confirmAction, confirmOpen } from '@/composables/useConfirm';
import type { LogEntry } from '@/lib/combatLog';
import { actionStatus, defaultStatLabels, spendAction, type ActionEffect } from '@/lib/encounter';
import { playerView, playerViewStorageKey, writePlayerView } from '@/lib/playerView';
import { rowsFromStats, statRows, statsFromRows, type StatRow } from '@/lib/stats';
import type { SharedData } from '@/types';
import type { Combatant, Creature, CreatureAction } from '@/types/tracker';
import { usePage } from '@inertiajs/vue3';
import { RotateCcw, Square } from 'lucide-vue-next';
import { computed, inject, onBeforeUnmount, onMounted, provide, ref, watch, type InjectionKey } from 'vue';
import { useCombat } from './useCombat';
import { useEncounterFile, type TrackerProps } from './useEncounterFile';
import { useLiveSync } from './useLiveSync';
import { useUndo } from './useUndo';

/**
 * Everything the tracker page runs on, put together from the pieces in this folder plus the
 * screen's own state (what's selected, which dialog is open). The page provides it, and its parts
 * (EncounterBar, TurnBar, InitiativeList, CombatantPanel…) take what they need with useTrackerContext().
 */
export function useTracker(props: TrackerProps) {
    const page = usePage<SharedData>();

    // The encounter and the fight in it.
    const encounterId = ref<number | null>(null);
    const name = ref('');
    // The campaign this encounter belongs to, if any.
    const campaignId = ref<number | null>(null);
    const combatants = ref<Combatant[]>([]);
    const round = ref(1);
    const activeIndex = ref(0);
    const log = ref<LogEntry[]>([]);
    const fight = { combatants, round, activeIndex, log };

    // What's on screen.
    const selectedId = ref<string | null>(null);
    const amount = ref('');
    const addOpen = ref(false);
    const groupOpen = ref(false);
    const panelTab = ref<'combatant' | 'history'>('combatant');

    const savedEncounters = computed(() => props.savedEncounters);
    const creatures = computed(() => props.creatures);
    const campaigns = computed(() => props.campaigns);
    const campaign = computed(() => props.campaigns.find((c) => c.id === campaignId.value));
    const limits = computed(() => page.props.limits);

    const creaturesById = computed(() => new Map(props.creatures.map((c) => [c.id, c])));
    // Quick-added combatants have no creature, so no stat block or actions.
    const creatureOf = (combatant: Combatant) => (combatant.creatureId === null ? undefined : creaturesById.value.get(combatant.creatureId));

    const undoing = useUndo(fight, {
        afterUndo: () => {
            if (!combatants.value.some((c) => c.id === selectedId.value)) selectedId.value = null;
            // Prompts for saves that may no longer apply; the history shows what was resolved.
            combat.concentrationChecks.value = [];
        },
    });
    const combat = useCombat(fight, { ...undoing, creatureOf, selectedId });
    const file = useEncounterFile(
        props,
        { encounterId, name, campaignId, ...fight },
        {
            creaturesById,
            onOpened: () => {
                selectedId.value = null;
                amount.value = '';
                undoing.clearUndo();
            },
        },
    );
    const live = useLiveSync({ name, campaignId, ...fight }, file.isGuest);

    const selected = computed(() => combatants.value.find((c) => c.id === selectedId.value) ?? combat.active.value ?? combatants.value[0]);
    const selectedCreature = computed(() => (selected.value ? creatureOf(selected.value) : undefined));

    const playersStop = () => (campaign.value ? ' Players stop seeing the fight.' : '');

    // The fight's over, but its wounds aren't: everyone stays as they are for whatever comes next.
    const endCombat = async () => {
        const ok = await confirmAction({
            title: `End combat in "${name.value}"?`,
            message: `It goes back to setup with the history cleared. HP, conditions and anything used per day stay as they are; actions limited per turn, round or encounter come back.${playersStop()} You can undo this.`,
            confirmLabel: 'End combat',
            icon: Square,
        });
        if (!ok) return;

        combat.endFight();
        selectedId.value = null;
        live.endLive();
    };

    // As if the fight never happened, e.g. to run the same encounter again from the start.
    const resetCombat = async () => {
        const ok = await confirmAction({
            title: `Reset "${name.value}"?`,
            message: `Everyone goes back to full HP with no conditions and every action restored, the history is cleared, and it goes back to setup.${playersStop()} You can undo this.`,
            confirmLabel: 'Reset',
            destructive: true,
            icon: RotateCcw,
        });
        if (!ok) return;

        combat.resetFight();
        selectedId.value = null;
        live.endLive();
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
    const addParty = () => combat.addEach('Add party', missingParty.value);

    // --- The damage / healing box for the selected combatant ---

    const amountValue = () => {
        const value = Number.parseInt(amount.value, 10);
        return Number.isNaN(value) || value <= 0 ? null : value;
    };

    const applyHp = (direction: 1 | -1) => {
        const value = amountValue();
        if (!selected.value || value === null) return;
        combat.damageOrHeal(selected.value, direction * value);
        amount.value = '';
    };

    const applyTempHp = () => {
        const value = amountValue();
        if (!selected.value || value === null) return;
        combat.giveTempHp(selected.value, Math.min(value, 100000));
        amount.value = '';
    };

    const onAmountKeydown = (event: KeyboardEvent) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        applyHp(event.shiftKey ? 1 : -1);
    };

    // --- Using an action: the dialog picks targets and any damage or healing; this records it all as one step ---

    const actionOpen = ref(false);
    const actionActorId = ref<string | null>(null);
    const actionName = ref<string | null>(null);
    const actionActor = computed(() => combatants.value.find((c) => c.id === actionActorId.value));
    const chosenAction = computed(() =>
        actionActor.value ? creatureOf(actionActor.value)?.actions.find((a) => a.name === actionName.value) : undefined,
    );

    // Whether the chosen action is ready; the dialog warns, but still lets it be used.
    const chosenStatus = computed(() =>
        actionActor.value && chosenAction.value ? actionStatus(actionActor.value, chosenAction.value, creatureOf(actionActor.value)) : null,
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
        undoing.change(`${action.name}`, () => {
            spendAction(actor, action);
            undoing.addLog({
                type: 'action',
                actor: actor.name,
                detail: action.name,
                targets: targets.length ? targets.map((t) => t.name) : undefined,
                amount: effect === 'none' ? undefined : value,
                effect: effect === 'none' ? undefined : effect,
            });
            if (effect !== 'none') {
                for (const target of targets) combat.changeHp(target, effect === 'damage' ? -value : value, { record: false });
            }
        });
    };

    // --- Editing a quick-added combatant's own stats. Undoable, but not worth a history entry ---

    const editingStats = ref(false);
    const statDraft = ref<StatRow[]>([]);
    const startEditingStats = (combatant: Combatant) => {
        statDraft.value = combatant.stats?.length ? rowsFromStats(combatant.stats) : statRows(defaultStatLabels);
        editingStats.value = true;
    };
    const saveStats = (combatant: Combatant) => {
        const stats = statsFromRows(statDraft.value);
        undoing.change(`Stats for ${combatant.name}`, () => {
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

    // --- Player view ---

    const showPlayerView = ref(false);
    // Outside a campaign there's no setting, so enemies show as bands, the campaign default.
    const enemyHp = computed(() => campaign.value?.enemyHp ?? 'bands');
    const localPlayerView = computed(() =>
        playerView(
            { name: name.value, round: round.value, activeIndex: activeIndex.value, combatants: combatants.value, log: log.value },
            enemyHp.value,
        ),
    );

    // The same-computer second window reads this. Only the filtered view is written.
    const playerViewKey = playerViewStorageKey(page.props.auth.user?.id ?? null);
    watch(localPlayerView, (view) => writePlayerView(playerViewKey, view), { deep: true, immediate: true });

    // A campaign fight goes to the campaign's own full-screen view, which also works on another device;
    // anything else to the same-computer one.
    const openPlayerWindow = () => {
        const url = campaign.value && !file.isGuest ? route('campaigns.combat', campaign.value.id) : route('player-view');
        window.open(url, 'ttrpg-player-view', 'popup,width=1280,height=800');
    };

    // --- Keyboard: N / P step turns, Alt+↑ / Alt+↓ move the selected combatant, Ctrl+Z (⌘Z) undoes ---
    // All ignored while typing or with a dialog open.
    const onKeydown = (event: KeyboardEvent) => {
        const target = event.target as HTMLElement;
        if (addOpen.value || actionOpen.value || groupOpen.value || confirmOpen.value || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName))
            return;

        if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            undoing.undo();
            return;
        }
        if (event.metaKey || event.ctrlKey) return;

        if (event.altKey) {
            if (!selected.value || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;
            event.preventDefault();
            combat.moveCombatant(selected.value, event.key === 'ArrowUp' ? -1 : 1);
            return;
        }

        if (event.key === 'n') combat.step(1);
        if (event.key === 'p') combat.step(-1);
    };
    onMounted(() => window.addEventListener('keydown', onKeydown));
    onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

    file.start();
    live.markLiveBaseline();

    return {
        // The encounter
        encounterId,
        name,
        campaignId,
        campaign,
        savedEncounters,
        creatures,
        campaigns,
        limits,
        ...file,
        // The fight
        combatants,
        round,
        activeIndex,
        log,
        ...combat,
        lastUndo: undoing.lastUndo,
        undo: undoing.undo,
        endCombat,
        resetCombat,
        missingParty,
        addParty,
        creatureOf,
        // On screen
        selectedId,
        selected,
        selectedCreature,
        amount,
        applyHp,
        applyTempHp,
        onAmountKeydown,
        addOpen,
        groupOpen,
        panelTab,
        actionOpen,
        actionActor,
        chosenAction,
        chosenStatus,
        openAction,
        useAction,
        editingStats,
        statDraft,
        startEditingStats,
        saveStats,
        // Player view
        showPlayerView,
        enemyHp,
        localPlayerView,
        openPlayerWindow,
        liveStatus: live.liveStatus,
        pickedCampaign: live.pickedCampaign,
    };
}

export type Tracker = ReturnType<typeof useTracker>;

const trackerKey: InjectionKey<Tracker> = Symbol('tracker');

/** The page calls this once; its parts get the same tracker with useTrackerContext(). */
export const provideTracker = (props: TrackerProps) => {
    const tracker = useTracker(props);
    provide(trackerKey, tracker);
    return tracker;
};

export const useTrackerContext = (): Tracker => {
    const tracker = inject(trackerKey);
    if (!tracker) throw new Error('useTrackerContext() needs the tracker page above it.');
    return tracker;
};
