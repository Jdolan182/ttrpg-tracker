import type { CreatureStat } from '@/types/tracker';

export type StatDisplay = 'score_modifier' | 'modifier_score' | 'score';

// A stat being typed in by hand (StatsEditor). A blank value means "leave this one out".
export interface StatRow {
    label: string;
    value: number | string;
}

export const statRows = (labels: string[]): StatRow[] => labels.map((label) => ({ label, value: '' }));

export const rowsFromStats = (stats: CreatureStat[] = []): StatRow[] => stats.map((s) => ({ label: s.label, value: s.value }));

/** The rows that have a name and a whole-number value, as stats. Duplicate names keep the first. */
export const statsFromRows = (rows: StatRow[]): CreatureStat[] => {
    const seen = new Set<string>();
    const stats: CreatureStat[] = [];
    for (const row of rows) {
        const label = row.label.trim().slice(0, 20);
        const value = Number(row.value);
        if (!label || row.value === '' || !Number.isInteger(value) || seen.has(label.toLowerCase())) continue;
        seen.add(label.toLowerCase());
        stats.push({ label, value: Math.max(-1000, Math.min(1000, value)) });
    }
    return stats;
};

export const statDisplays: { value: StatDisplay; label: string; example: string }[] = [
    { value: 'score_modifier', label: 'Score with modifier', example: '18 (+4)' },
    { value: 'modifier_score', label: 'Modifier with score', example: '+4 (18)' },
    { value: 'score', label: 'Score only', example: '18' },
];

// The d20 convention (D&D, Pathfinder and friends): every 2 points above or below 10 is +1 or -1.
export const modifier = (score: number) => Math.floor((score - 10) / 2);

export const formatModifier = (value: number) => (value >= 0 ? `+${value}` : `−${Math.abs(value)}`);

/** Main value and the secondary one shown in brackets (null when there isn't one). */
export const statParts = (score: number, display: StatDisplay): { main: string; extra: string | null } => {
    const mod = formatModifier(modifier(score));
    if (display === 'modifier_score') return { main: mod, extra: String(score) };
    if (display === 'score') return { main: String(score), extra: null };
    return { main: String(score), extra: mod };
};
