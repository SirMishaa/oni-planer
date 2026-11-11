import { usePage } from '@inertiajs/vue3';

/** Nested translation catalogs, as shared by `HandleInertiaRequests`. */
export type Translations = { [key: string]: string | Translations };

export type Replacements = Record<string, string | number>;

// region helpers
function replacePlaceholders(text: string, replace: Replacements): string {
    return Object.entries(replace)
        .sort(([a], [b]) => b.length - a.length)
        .reduce(
            (result, [name, value]) =>
                result.replaceAll(`:${name}`, String(value)),
            text,
        );
}

/** Reads a dotted key (`colony.verdict.holds`); a missing key returns the key itself. */
export function translate(
    dict: Translations,
    key: string,
    replace: Replacements = {},
): string {
    let node: string | Translations | undefined = dict;
    for (const part of key.split('.')) {
        node = typeof node === 'object' ? node[part] : undefined;
    }

    return typeof node === 'string' ? replacePlaceholders(node, replace) : key;
}

/**
 * Picks the `one|other` form: the first part for one (French: also zero),
 * else the second, with `:count` formatted for the locale.
 */
export function choose(
    text: string,
    count: number,
    locale: string,
    replace: Replacements = {},
): string {
    const [one = text, other = one] = text.split('|');
    const singular = locale.startsWith('fr') ? count < 2 : count === 1;

    return replacePlaceholders(singular ? one : other, {
        ...replace,
        count: new Intl.NumberFormat(locale).format(count),
    });
}
// endregion

export function useT(): {
    t(key: string, replace?: Replacements): string;
    tc(key: string, count: number, replace?: Replacements): string;
} {
    const page = usePage<{ locale: string; translations: Translations }>();

    return {
        t: (key, replace = {}) =>
            translate(page.props.translations, key, replace),
        tc: (key, count, replace = {}) => {
            const text = translate(page.props.translations, key);

            return text === key
                ? key
                : choose(text, count, page.props.locale, replace);
        },
    };
}
