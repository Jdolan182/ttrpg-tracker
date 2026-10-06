// The fight in progress is kept in localStorage so it survives a page refresh. This is a
// per-browser convenience, not saving: storage can be cleared or blocked, so every access is guarded.
import type { LogEntry } from '@/lib/combatLog';
import type { Combatant } from '@/types/tracker';

export interface StoredTracker {
    // Bumped whenever the shape changes; older data is ignored rather than migrated.
    version: 2;
    // The saved encounter being edited, or null for one that has never been saved.
    encounterId: number | null;
    name: string;
    // The campaign the encounter belongs to. Missing in older stored fights.
    campaignId?: number | null;
    combatants: Combatant[];
    // 0 while setting up (before "Start combat"), then 1, 2, 3…
    round: number;
    activeIndex: number;
    // Combat history; older stored fights have none, which readTracker() fills in as empty.
    log: LogEntry[];
    // Fingerprint of the encounter as last saved or opened, for the "Unsaved changes" indicator
    // after a refresh without downloading the saved copy again. Missing in older stored fights.
    savedFingerprint?: string;
}

// Keeps the app's old name: changing the key would lose everyone's fight in progress.
export const trackerStorageKey = (userId: number | null) => `ttrpg-tracker:encounter:${userId ? `user-${userId}` : 'guest'}`;

export const readTracker = (key: string): StoredTracker | null => {
    try {
        const raw = window.localStorage.getItem(key);
        if (!raw) return null;

        const stored = JSON.parse(raw) as StoredTracker;
        const isValid =
            stored.version === 2 &&
            (stored.encounterId === null || Number.isInteger(stored.encounterId)) &&
            typeof stored.name === 'string' &&
            Array.isArray(stored.combatants) &&
            Number.isInteger(stored.round) &&
            stored.round >= 0 &&
            Number.isInteger(stored.activeIndex) &&
            stored.activeIndex >= 0 &&
            stored.activeIndex <= Math.max(0, stored.combatants.length - 1);

        if (!isValid) return null;
        if (!Array.isArray(stored.log)) stored.log = [];
        return stored;
    } catch {
        return null;
    }
};

export const writeTracker = (key: string, state: StoredTracker) => {
    try {
        window.localStorage.setItem(key, JSON.stringify(state));
    } catch {
        // Storage full or unavailable (e.g. private mode): the tracker keeps working, it just won't survive a refresh.
    }
};

export const removeTracker = (key: string) => {
    try {
        window.localStorage.removeItem(key);
    } catch {
        // Nothing to clean up if storage is unavailable.
    }
};
