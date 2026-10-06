import { logEntry, MAX_LOG_ENTRIES, type LogEntry } from '@/lib/combatLog';
import type { Encounter } from '@/types/tracker';
import { computed, ref } from 'vue';
import type { FightState } from './types';

// Undo: every change to the fight snapshots it first, so the last few can be stepped back one at a time.
// Kept in memory only; a refresh starts a fresh undo history (the combat history itself is kept).
//
// The history isn't copied into each step: changes only ever add entries to the end, so a step just
// remembers the newest entry at the time and undo removes everything after it. That keeps undo small
// however long the history gets. End combat is the one change that replaces the history, so it keeps a copy.
const MAX_UNDO = 100;

export interface UndoStep {
    label: string;
    // Combatants, round and turn before the change.
    state: string;
    // Newest history entry before the change (null when the history was empty).
    lastLogId: string | null;
    // The whole history, only for changes that replace it rather than add to it.
    fullLog?: string;
}

/** Changes to the fight, the combat history they write, and undoing them. */
export function useUndo({ combatants, round, activeIndex, log }: FightState, { afterUndo }: { afterUndo: () => void }) {
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
        afterUndo();
    };

    const clearUndo = () => {
        undoStack.value = [];
    };

    return { lastUndo, undoStep, pushUndo, addLog, change, undo, clearUndo };
}
