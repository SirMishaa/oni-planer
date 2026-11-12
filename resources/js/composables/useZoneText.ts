import { kelvinToCelsius } from '@/components/zones/zoneMapGeometry';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';

const CYCLE_SECONDS = 600;

/** Wording shared by the zone map, panel and entities table. */
export function useZoneText(): {
    duration(seconds: number): string;
    percent(share: number): string;
    position(x: number | null, y: number | null): string;
    celsius(kelvin: number): string;
    mass(kilograms: number): string;
} {
    const i18n = useT();
    const format = useFormat();

    return {
        /** `21 s`, `2 min 41 s`, then cycles beyond one cycle. */
        duration: (seconds) => {
            const whole = Math.max(0, Math.round(seconds));

            if (whole < 60) {
                return i18n.t('zones.time.seconds', { s: whole });
            }

            if (whole < CYCLE_SECONDS) {
                return i18n.t('zones.time.minutes', {
                    m: Math.floor(whole / 60),
                    s: String(whole % 60).padStart(2, '0'),
                });
            }

            return i18n.tc(
                'zones.time.cycles',
                Math.round((whole / CYCLE_SECONDS) * 10) / 10,
            );
        },
        percent: (share) => `${format.number(share * 100)} %`,
        position: (x, y) => (x === null || y === null ? '' : `${x}, ${y}`),
        celsius: (kelvin) => `${format.number(kelvinToCelsius(kelvin), 1)} °C`,
        mass: (kilograms) =>
            i18n.t('zones.map.mass', {
                mass: format.number(kilograms, kilograms < 10 ? 2 : 0),
            }),
    };
}
