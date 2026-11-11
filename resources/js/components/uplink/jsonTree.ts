/** What a folded JSON node shows: a count for containers, the value otherwise. */
export type JsonSummary =
    | { kind: 'array' | 'object'; count: number }
    | { kind: 'literal'; text: string };

/** One child of a container: its key (index for arrays) and value. */
export type JsonEntry = { key: string; value: unknown };

export function isContainer(value: unknown): value is object {
    return typeof value === 'object' && value !== null;
}

/** Summary of a JSON value, as structure: the component translates counts. */
export function summarize(value: unknown): JsonSummary {
    if (Array.isArray(value)) {
        return { kind: 'array', count: value.length };
    }

    if (isContainer(value)) {
        return { kind: 'object', count: Object.keys(value).length };
    }

    return { kind: 'literal', text: JSON.stringify(value) ?? String(value) };
}

/** The first `limit` children of a container, in order. */
export function entries(value: object, limit: number): JsonEntry[] {
    if (Array.isArray(value)) {
        return value.slice(0, limit).map((item: unknown, index) => ({
            key: String(index),
            value: item,
        }));
    }

    return Object.entries(value)
        .slice(0, limit)
        .map(([key, item]: [string, unknown]) => ({ key, value: item }));
}
