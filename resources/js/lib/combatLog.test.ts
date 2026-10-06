import { describe, expect, it } from 'vitest';
import { byRound, describe as describeEntry, listNames, type LogEntry } from './combatLog';

const entry = (fields: Partial<LogEntry> & Pick<LogEntry, 'type'>, round = 1): LogEntry => ({
    id: crypto.randomUUID(),
    at: '2026-10-06T10:00:00Z',
    round,
    ...fields,
});

describe('combat history', () => {
    it('lists names the way people say them', () => {
        expect(listNames(['Aria'])).toBe('Aria');
        expect(listNames(['Aria', 'Borin'])).toBe('Aria and Borin');
        expect(listNames(['Aria', 'Borin', 'Kestrel'])).toBe('Aria, Borin and Kestrel');
        expect(listNames([])).toBe('someone');
    });

    it('describes entries in plain English', () => {
        expect(describeEntry(entry({ type: 'damage', targets: ['Aria', 'Borin'], amount: 8 }))).toBe('Aria and Borin took 8 damage');
        expect(describeEntry(entry({ type: 'action', actor: 'Goblin', detail: 'Scimitar', targets: ['Aria'], amount: 5, effect: 'damage' }))).toBe(
            'Goblin used Scimitar on Aria: 5 damage',
        );
    });

    it('words joining with or without an initiative', () => {
        expect(describeEntry(entry({ type: 'joined', targets: ['Wolf'], amount: 0 }))).toBe('Wolf joined the fight (initiative 0)');
        expect(describeEntry(entry({ type: 'joined', targets: ['Aria'] }))).toBe('Aria joined the fight');
    });

    it("words healing without the amount when players aren't allowed to see it", () => {
        expect(describeEntry(entry({ type: 'heal', targets: ['Goblin'] }))).toBe('Goblin regained HP');
        expect(describeEntry(entry({ type: 'temp_hp', targets: ['Goblin'] }))).toBe('Goblin gained temporary HP');
    });

    it('groups by round and turn, newest first, keeping entries in order within a turn', () => {
        const log = [
            entry({ type: 'combat_started' }),
            entry({ type: 'turn', actor: 'Aria' }),
            entry({ type: 'damage', targets: ['Goblin'], amount: 3 }),
            entry({ type: 'heal', targets: ['Aria'], amount: 2 }),
            entry({ type: 'round' }, 2),
            entry({ type: 'turn', actor: 'Goblin' }, 2),
        ];
        const rounds = byRound(log);
        expect(rounds.map((r) => r.round)).toEqual([2, 1]);
        expect(rounds[1].turns.map((t) => t.actor)).toEqual(['Aria', null]);
        expect(rounds[1].turns[0].entries.map((e) => e.type)).toEqual(['damage', 'heal']);
        // "Round 2 began" is the heading, not an entry.
        expect(rounds[0].turns[0].entries).toEqual([]);
    });
});
