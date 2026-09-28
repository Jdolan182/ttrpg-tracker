export type StatDisplay = 'score_modifier' | 'modifier_score' | 'score';

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
