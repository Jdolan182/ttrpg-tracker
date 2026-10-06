import type { Combatant } from '@/types/tracker';
import { describe, expect, it } from 'vitest';
import shared from '../../../tests/fixtures/player-view.json';
import type { LogEntry } from './combatLog';
import { playerView } from './playerView';

// The same cases tests/Feature/PlayerViewParityTest.php checks the server's copy against.
const fight = shared.fight as unknown as { name: string; round: number; activeIndex: number; combatants: Combatant[]; log: LogEntry[] };

describe('playerView', () => {
    it.each(shared.cases)('matches the shared case with enemy HP "$enemyHp"', ({ enemyHp, expected }) => {
        expect(playerView(fight, enemyHp as 'bands' | 'exact')).toEqual(expected);
    });

    it('shows nothing while the fight is being set up', () => {
        expect(playerView({ ...fight, round: 0 }, 'bands')).toBeNull();
    });

    it('only shows an enemy dropping when HP is hidden', () => {
        const view = playerView(fight, 'hidden')!;
        expect(view.combatants.find((c) => c.name === 'Goblin')).toMatchObject({ hp: null, status: null });
        expect(view.combatants.find((c) => c.name === 'Orc')).toMatchObject({ hp: null, status: 'down' });
        // Players and allies always see their own side's numbers.
        expect(view.combatants.find((c) => c.name === 'Guard')).toMatchObject({ hp: 10, status: 'bloodied' });
    });

    it("doesn't change the fight it's given", () => {
        const before = JSON.stringify(fight);
        playerView(fight, 'bands');
        expect(JSON.stringify(fight)).toBe(before);
    });
});
