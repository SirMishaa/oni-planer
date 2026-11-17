import { describe, expect, it } from 'vitest';
import { buildingSettingsLine } from './zoneBuildingSettings';

const strings: Record<string, string> = {
    'zones.entities.settings.filter': 'Filtre : :names',
    'zones.entities.settings.nothing_ticked': 'Rien de coché',
    'zones.entities.settings.capacity_kg': ':stored / :limit kg',
    'zones.entities.settings.capacity_critters': ':stored / :limit critters',
    'zones.entities.settings.max_kg': 'Maximum : :max kg',
    'zones.entities.settings.max_critters': 'Maximum : :max critters',
};
const tools = {
    t: (key: string, replacements: Record<string, string | number> = {}) =>
        Object.entries(replacements).reduce(
            (text, [name, value]) => text.replace(`:${name}`, String(value)),
            strings[key],
        ),
    number: (value: number) => String(value).replace('.', ','),
};

describe('buildingSettingsLine', () => {
    it('is null without settings', () => {
        expect(buildingSettingsLine(null, tools)).toBeNull();
    });

    it('joins a feeder filter and its capacity, the maximum in the title', () => {
        expect(
            buildingSettingsLine(
                {
                    filter: ['Algue', 'Varech'],
                    capacity: {
                        limit: 500,
                        max: 2000,
                        stored: 340,
                        unit: 'kg',
                    },
                },
                tools,
            ),
        ).toEqual({
            text: 'Filtre : Algue, Varech · 340 / 500 kg',
            title: 'Maximum : 2000 kg',
        });
    });

    it('reads an empty filter as nothing ticked, not as no filter', () => {
        expect(
            buildingSettingsLine({ filter: [], capacity: null }, tools)?.text,
        ).toBe('Rien de coché');
        expect(
            buildingSettingsLine({ filter: null, capacity: null }, tools),
        ).toBeNull();
    });

    it('prints a drop-off as its count then its critters, with a decimal comma', () => {
        expect(
            buildingSettingsLine(
                {
                    filter: ['Pacu'],
                    capacity: {
                        limit: 20,
                        max: 30,
                        stored: 12.5,
                        unit: 'critters',
                    },
                },
                tools,
            )?.text,
        ).toBe('12,5 / 20 critters · Pacu');
    });

    it('falls back to the maximum when no limit was set', () => {
        expect(
            buildingSettingsLine(
                {
                    filter: null,
                    capacity: { limit: null, max: 2000, stored: 0, unit: 'kg' },
                },
                tools,
            )?.text,
        ).toBe('0 / 2000 kg');
    });
});
