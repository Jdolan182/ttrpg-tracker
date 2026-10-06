import { describe, expect, it } from 'vitest';
import { formatModifier, modifier, statParts, statsFromRows } from './stats';

describe('stats', () => {
    it('uses the d20 modifier rule, rounding down', () => {
        expect([1, 8, 9, 10, 11, 18, 30].map(modifier)).toEqual([-5, -1, -1, 0, 0, 4, 10]);
        expect(formatModifier(4)).toBe('+4');
        expect(formatModifier(0)).toBe('+0');
        expect(formatModifier(-1)).toBe('−1');
    });

    it('shows a stat the way the user chose', () => {
        expect(statParts(18, 'score_modifier')).toEqual({ main: '18', extra: '+4' });
        expect(statParts(18, 'modifier_score')).toEqual({ main: '+4', extra: '18' });
        expect(statParts(18, 'score')).toEqual({ main: '18', extra: null });
    });

    it('keeps only named, whole-number rows, first of each name, within range', () => {
        expect(
            statsFromRows([
                { label: ' STR ', value: '16' },
                { label: 'DEX', value: '' },
                { label: '', value: 12 },
                { label: 'CON', value: '1.5' },
                { label: 'str', value: 8 },
                { label: 'WIS', value: 5000 },
            ]),
        ).toEqual([
            { label: 'STR', value: 16 },
            { label: 'WIS', value: 1000 },
        ]);
    });
});
