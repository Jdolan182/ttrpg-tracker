import type { Creature } from '@/types/tracker';

/**
 * A creature's rating as a number for sorting: "CR 1/4" is 0.25, "Level 5" is 5, "Tier 2" is 2.
 * Ratings are free text (any game system), so one without a number gives null and sorts last.
 */
export const ratingValue = (rating: string): number | null => {
    const fraction = /(\d+)\s*\/\s*(\d+)/.exec(rating);
    if (fraction && Number(fraction[2]) > 0) return Number(fraction[1]) / Number(fraction[2]);
    const number = /\d+(?:\.\d+)?/.exec(rating);
    return number ? Number(number[0]) : null;
};

export type CompendiumSort = 'name' | 'rating' | 'rating-desc';

export const compendiumSorts: { value: CompendiumSort; label: string }[] = [
    { value: 'name', label: 'Name' },
    { value: 'rating', label: 'Rating, low to high' },
    { value: 'rating-desc', label: 'Rating, high to low' },
];

/** Sorted copy of `creatures`. Same ratings (and unrated ones, which go last) are by name. */
export const sortCreatures = (creatures: Creature[], sort: CompendiumSort): Creature[] => {
    const byName = (a: Creature, b: Creature) => a.name.localeCompare(b.name);
    if (sort === 'name') return [...creatures].sort(byName);

    const direction = sort === 'rating' ? 1 : -1;
    return [...creatures].sort((a, b) => {
        const [x, y] = [ratingValue(a.rating), ratingValue(b.rating)];
        if (x === null || y === null) return (x === null ? 1 : 0) - (y === null ? 1 : 0) || byName(a, b);
        return (x - y) * direction || byName(a, b);
    });
};
