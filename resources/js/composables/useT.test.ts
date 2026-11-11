import { describe, expect, it } from 'vitest';
import { choose, translate } from './useT';

describe('translate', () => {
    it('reads a dotted key and replaces placeholders', () => {
        expect(
            translate({ colony: { a: { b: 'Salut :name' } } }, 'colony.a.b', {
                name: 'Ruby',
            }),
        ).toBe('Salut Ruby');
    });

    it('returns the key when it is missing', () => {
        expect(translate({ colony: { a: {} } }, 'colony.a.missing')).toBe(
            'colony.a.missing',
        );
    });

    it('returns the key when it points to a group', () => {
        expect(translate({ colony: { a: { b: 'x' } } }, 'colony.a')).toBe(
            'colony.a',
        );
    });
});

describe('choose', () => {
    const text = ':count cycle|:count cycles';

    it('uses the singular for one in english', () => {
        expect(choose(text, 1, 'en')).toBe('1 cycle');
    });

    it('uses the plural for zero in english', () => {
        expect(choose(text, 0, 'en')).toBe('0 cycles');
    });

    it('uses the singular for zero in french', () => {
        expect(choose(text, 0, 'fr')).toBe('0 cycle');
    });

    it('uses the plural for two in french', () => {
        expect(choose(text, 2, 'fr')).toBe('2 cycles');
    });

    it('formats the count for the locale', () => {
        expect(choose(text, 1500, 'en')).toBe('1,500 cycles');
    });
});
