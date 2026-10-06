import type { Creature } from '@/types/tracker';
import { describe, expect, it } from 'vitest';
import { ratingValue, sortCreatures } from './compendium';

const creature = (name: string, rating: string) => ({ name, rating }) as Creature;

describe('ratings', () => {
    it('reads fractions, whole numbers and other systems', () => {
        expect(ratingValue('CR 1/4')).toBe(0.25);
        expect(ratingValue('CR 0')).toBe(0);
        expect(ratingValue('CR 17')).toBe(17);
        expect(ratingValue('Level 5')).toBe(5);
        expect(ratingValue('Tier 2')).toBe(2);
        expect(ratingValue('Boss')).toBeNull();
        expect(ratingValue('')).toBeNull();
    });

    it('sorts by rating either way, then by name, with unrated ones last', () => {
        const list = [
            creature('Ogre', 'CR 2'),
            creature('Rat', 'CR 0'),
            creature('Mook', ''),
            creature('Goblin', 'CR 1/4'),
            creature('Bear', 'CR 2'),
        ];
        expect(sortCreatures(list, 'rating').map((c) => c.name)).toEqual(['Rat', 'Goblin', 'Bear', 'Ogre', 'Mook']);
        expect(sortCreatures(list, 'rating-desc').map((c) => c.name)).toEqual(['Bear', 'Ogre', 'Goblin', 'Rat', 'Mook']);
        expect(sortCreatures(list, 'name').map((c) => c.name)).toEqual(['Bear', 'Goblin', 'Mook', 'Ogre', 'Rat']);
        // The original list is left alone.
        expect(list[0].name).toBe('Ogre');
    });
});
