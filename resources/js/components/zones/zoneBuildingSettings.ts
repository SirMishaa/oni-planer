import type { BuildingSettingsData } from '@/types/App/Data/data.d';

/** What the settings line needs from the page: the translator and the number format. */
export type SettingsLineTools = {
    t: (key: string, replacements?: Record<string, string | number>) => string;
    number: (value: number, maximumFractionDigits?: number) => string;
};

/**
 * The one line of a building's settings: `Filtre : Algue, Varech · 340 / 500 kg`, or for a
 * drop-off `12 / 20 critters · Pacu`. A filter with nothing ticked reads `Rien de coché`, never
 * as no filter. `title` carries the maximum capacity. Null when the game sent no settings.
 */
export function buildingSettingsLine(
    settings: BuildingSettingsData | null,
    { t, number }: SettingsLineTools,
): { text: string; title: string | null } | null {
    if (settings === null) {
        return null;
    }

    const { filter, capacity } = settings;
    const names =
        filter === null
            ? null
            : filter.length === 0
              ? t('zones.entities.settings.nothing_ticked')
              : filter.join(', ');
    const parts: string[] = [];
    let title: string | null = null;

    if (capacity === null) {
        if (names !== null) {
            parts.push(
                filter?.length === 0
                    ? names
                    : t('zones.entities.settings.filter', { names }),
            );
        }
    } else {
        const stored =
            capacity.stored === null ? '?' : number(capacity.stored, 1);
        const limit = capacity.limit ?? capacity.max;
        parts.push(
            t(`zones.entities.settings.capacity_${capacity.unit}`, {
                stored,
                limit: limit === null ? '?' : number(limit, 1),
            }),
        );

        if (capacity.max !== null) {
            title = t(`zones.entities.settings.max_${capacity.unit}`, {
                max: number(capacity.max, 1),
            });
        }

        if (names !== null) {
            const filterText =
                filter?.length === 0 || capacity.unit === 'critters'
                    ? names
                    : t('zones.entities.settings.filter', { names });
            parts[capacity.unit === 'kg' ? 'unshift' : 'push'](filterText);
        }
    }

    return parts.length === 0 ? null : { text: parts.join(' · '), title };
}
