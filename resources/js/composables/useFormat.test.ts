import { describe, expect, it } from 'vitest';
import { relativeAge } from './useFormat';

const expected = (value: number, unit: Intl.RelativeTimeFormatUnit): string =>
    new Intl.RelativeTimeFormat('fr', {
        numeric: 'auto',
        style: 'short',
    }).format(value, unit);

describe('relativeAge', () => {
    it('uses the largest whole unit', () => {
        expect(relativeAge((2 * 3600 + 25 * 60) * 1000, 'fr')).toBe(
            expected(-2, 'hour'),
        );
    });

    it('counts minutes under an hour', () => {
        expect(relativeAge(59 * 60 * 1000, 'fr')).toBe(expected(-59, 'minute'));
    });

    it('counts days beyond a day', () => {
        expect(relativeAge(3 * 86_400 * 1000, 'fr')).toBe(expected(-3, 'day'));
    });

    it('treats a future time as now', () => {
        expect(relativeAge(-5000, 'fr')).toBe(expected(-0, 'second'));
    });
});
