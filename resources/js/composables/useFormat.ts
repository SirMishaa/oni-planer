import { usePage } from '@inertiajs/vue3';

// region helpers
const RELATIVE_UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
    ['second', 1],
];

/** "il y a 2 h" / "2 hr. ago" for a duration in milliseconds before now. */
export function relativeAge(elapsedMs: number, locale: string): string {
    const seconds = Math.max(0, Math.round(elapsedMs / 1000));
    const [unit, size] = RELATIVE_UNITS.find(
        ([, unitSeconds]) => seconds >= unitSeconds,
    ) ?? ['second', 1];

    return new Intl.RelativeTimeFormat(locale, {
        numeric: 'auto',
        style: 'short',
    }).format(-Math.floor(seconds / size), unit);
}
// endregion

/** Locale-aware number, list and time formatting for the colony pages. */
export function useFormat(): {
    locale(): string;
    number(value: number, maximumFractionDigits?: number): string;
    compact(value: number): string;
    list(items: string[]): string;
    age(elapsedMs: number): string;
} {
    const page = usePage<{ locale: string }>();
    const locale = (): string => page.props.locale;

    return {
        locale,
        number: (value, maximumFractionDigits = 0) =>
            new Intl.NumberFormat(locale(), { maximumFractionDigits }).format(
                value,
            ),
        compact: (value) =>
            new Intl.NumberFormat(locale(), {
                notation: 'compact',
                maximumFractionDigits: 1,
            }).format(value),
        list: (items) =>
            new Intl.ListFormat(locale(), {
                style: 'long',
                type: 'conjunction',
            }).format(items),
        age: (elapsedMs) => relativeAge(elapsedMs, locale()),
    };
}
