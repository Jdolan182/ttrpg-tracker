// The combat history. Entries copy names rather than pointing at combatants, so they still read
// correctly after someone is renamed or removed. Mirrors EncounterPayload::LOG_TYPES on the server.

export type LogEntryType =
    | 'combat_started'
    | 'round'
    | 'turn'
    | 'damage'
    | 'heal'
    | 'down'
    | 'defeated'
    | 'revived'
    | 'condition_on'
    | 'condition_off'
    | 'side'
    | 'action'
    | 'joined'
    | 'removed'
    | 'moved'
    | 'sorted'
    | 'initiative_rolled'
    | 'condition_expired'
    | 'temp_hp'
    // detail: 'start' | 'end' | 'kept' | 'lost'; amount is the save DC for kept/lost.
    | 'concentration'
    // detail: 'success' | 'failure'; amount is how many of that kind they now have.
    | 'death_save'
    | 'stabilized'
    | 'died'
    | 'hidden'
    | 'revealed'
    | 'combat_ended'
    // A limited action coming back at the start of its owner's turn. actor: whose; detail: the action;
    // amount: the recharge roll, absent when a cooldown simply ran out. DM only (dmOnlyLogTypes).
    | 'recharged'
    | 'not_recharged';

// History players never see: whether an enemy's big attack is back is the DM's secret.
// Mirrors EncounterPayload::DM_ONLY_LOG_TYPES.
export const dmOnlyLogTypes: LogEntryType[] = ['recharged', 'not_recharged'];

export type LogEntry = {
    id: string;
    // ISO timestamp.
    at: string;
    // Round it happened in; 0 is setup.
    round: number;
    type: LogEntryType;
    // Who did it, e.g. whose turn it is or who used an action.
    actor?: string;
    // Who it happened to.
    targets?: string[];
    amount?: number;
    // For actions: whether `amount` was damage or healing.
    effect?: 'damage' | 'heal';
    // Type-specific text: the action or condition name, the new side, "back"…
    detail?: string;
};

export const MAX_LOG_ENTRIES = 1000;

const newId = () => (typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);

export const logEntry = (round: number, entry: Omit<LogEntry, 'id' | 'at' | 'round'>): LogEntry => ({
    id: newId(),
    at: new Date().toISOString(),
    round,
    ...entry,
});

/** "Aria", "Aria and Borin", "Aria, Borin and Kestrel". */
export const listNames = (names: string[] = []) =>
    names.length <= 1 ? (names[0] ?? 'someone') : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;

/** One line of plain English for an entry. */
export const describe = (entry: LogEntry): string => {
    const targets = listNames(entry.targets);

    switch (entry.type) {
        case 'combat_started':
            return 'Combat started';
        case 'round':
            return `Round ${entry.round} began`;
        case 'turn':
            return entry.detail === 'back' ? `Went back to ${entry.actor}'s turn` : `${entry.actor}'s turn`;
        case 'damage':
            return `${targets} took ${entry.amount} damage`;
        // The amount is left out of what players see when enemy HP is kept from them.
        case 'heal':
            return entry.amount === undefined ? `${targets} regained HP` : `${targets} regained ${entry.amount} HP`;
        case 'down':
            return `${targets} went down`;
        case 'defeated':
            return `${targets} was defeated`;
        case 'revived':
            return `${targets} is back up`;
        case 'condition_on':
            return `${targets} is now ${entry.detail}`;
        case 'condition_off':
            return `${targets} is no longer ${entry.detail}`;
        case 'side':
            return `${targets} is now ${entry.detail?.toLowerCase() === 'ally' ? 'an ally' : entry.detail?.toLowerCase()}`;
        case 'action': {
            const on = entry.targets?.length ? ` on ${targets}` : '';
            const result = entry.amount ? `: ${entry.amount} ${entry.effect === 'heal' ? 'healing' : 'damage'}` : '';
            return `${entry.actor} used ${entry.detail}${on}${result}`;
        }
        case 'joined':
            // No amount: they joined before entering their initiative.
            return entry.amount === undefined ? `${targets} joined the fight` : `${targets} joined the fight (initiative ${entry.amount})`;
        case 'removed':
            return `${targets} was removed`;
        case 'moved':
            return `${targets} moved in the turn order`;
        case 'sorted':
            return 'Turn order sorted by initiative';
        case 'initiative_rolled':
            return entry.detail === 'everyone' ? 'Initiative rolled for everyone' : 'Initiative rolled for monsters and NPCs';
        case 'condition_expired':
            return `${targets} is no longer ${entry.detail} (it wore off)`;
        case 'temp_hp':
            return entry.amount === undefined ? `${targets} gained temporary HP` : `${targets} gained ${entry.amount} temporary HP`;
        case 'concentration':
            if (entry.detail === 'start') return `${targets} started concentrating`;
            if (entry.detail === 'kept') return `${targets} kept concentration (DC ${entry.amount})`;
            if (entry.detail === 'lost') return `${targets} lost concentration (DC ${entry.amount})`;
            return `${targets} stopped concentrating`;
        case 'death_save':
            return entry.detail === 'success'
                ? `${targets} succeeded on a death save (${entry.amount} of 3)`
                : `${targets} failed a death save (${entry.amount} of 3)`;
        case 'stabilized':
            return `${targets} is stable`;
        case 'died':
            return `${targets} died`;
        case 'hidden':
            return `${targets} was hidden from players`;
        case 'revealed':
            return `${targets} was revealed to players`;
        case 'combat_ended':
            return 'Combat ended';
        case 'recharged':
            return entry.amount === undefined
                ? `${entry.actor}'s ${entry.detail} is ready again`
                : `${entry.actor}'s ${entry.detail} recharged (rolled ${entry.amount})`;
        case 'not_recharged':
            return `${entry.actor}'s ${entry.detail} didn't recharge (rolled ${entry.amount})`;
    }
};

export interface TurnGroup {
    key: string;
    // Whose turn it was; null for things that happened outside a turn (setup, combat starting).
    actor: string | null;
    // The start of this turn was trimmed off the history, so its heading is gone.
    continued: boolean;
    entries: LogEntry[];
}

/** The history is full, so older entries have been (or are about to be) dropped. */
export const isTrimmed = (log: LogEntry[]) => log.length >= MAX_LOG_ENTRIES;

/**
 * The history as rounds, each split into turns. Newest round and newest turn first, so the
 * current turn is on top, but entries within a turn read in the order they happened.
 */
export const byRound = (log: LogEntry[]) => {
    const rounds = new Map<number, TurnGroup[]>();
    const trimmed = isTrimmed(log);

    for (const [index, entry] of log.entries()) {
        if (entry.type === 'round') continue; // shown as the round heading instead

        const turns = rounds.get(entry.round) ?? [];
        rounds.set(entry.round, turns);

        if (entry.type === 'turn' || turns.length === 0) {
            turns.push({
                key: entry.id,
                actor: entry.type === 'turn' ? (entry.actor ?? null) : null,
                // Only the oldest surviving entries can have lost their turn heading to trimming.
                continued: trimmed && entry.type !== 'turn' && index === 0,
                entries: [],
            });
        }
        if (entry.type !== 'turn') turns[turns.length - 1].entries.push(entry);
    }

    return [...rounds.entries()].sort(([a], [b]) => b - a).map(([round, turns]) => ({ round, turns: [...turns].reverse() }));
};
