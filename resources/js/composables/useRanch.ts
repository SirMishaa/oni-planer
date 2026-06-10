import type {
    CritterFamilyData,
    CritterVariantData,
    ElementRefData,
    RanchCatalogData,
} from '@/types/App/Data/data';
import { computed, ref, type Ref } from 'vue';

// region Types
export type RanchCatalog = RanchCatalogData;

export interface RanchRow {
    critterId: string;
    foodElementId: string | null;
    count: number;
}

export interface ElementBalance {
    element_id: string;
    name: string;
    icon: string | null;
    url: string | null;
    net_per_cycle: number;
}

export interface EggBalance {
    element_id: string;
    label: string;
    icon: string | null;
    url: string | null;
    amount_per_cycle: number;
}

export interface RanchBalance {
    elements: ElementBalance[];
    eggs: EggBalance[];
}
// endregion

// region Pure helpers
function findVariant(
    catalog: RanchCatalog,
    critterId: string,
): CritterVariantData | null {
    for (const family of catalog.families as CritterFamilyData[]) {
        const v = family.variants.find((x) => x.critter_id === critterId);
        if (v) return v;
    }
    return null;
}

export function chosenDiet(
    variant: CritterVariantData,
    foodElementId: string | null,
) {
    const diets = variant.adult.diets;
    if (diets.length === 0) return null;
    if (foodElementId === null) return diets[0] ?? null;
    return diets.find((d) => d.consumed.element_id === foodElementId) ?? diets[0] ?? null;
}

export function computeBalance(
    catalog: RanchCatalog,
    rows: RanchRow[],
): RanchBalance {
    const net = new Map<string, ElementBalance>();
    const eggs = new Map<string, EggBalance>();

    const bump = (elemRef: ElementRefData, delta: number) => {
        const cur = net.get(elemRef.element_id) ?? {
            element_id: elemRef.element_id,
            name: elemRef.name,
            icon: elemRef.icon,
            url: elemRef.url,
            net_per_cycle: 0,
        };
        cur.net_per_cycle += delta;
        net.set(elemRef.element_id, cur);
    };

    for (const row of rows) {
        if (row.count <= 0) continue;
        const variant = findVariant(catalog, row.critterId);
        if (!variant) continue;

        const diet = chosenDiet(variant, row.foodElementId);
        if (diet) {
            bump(diet.consumed, -diet.amount_per_cycle * row.count);
            if (diet.produced) {
                const ratio = diet.conversion_ratio ?? 0;
                bump(
                    diet.produced,
                    diet.amount_per_cycle * ratio * row.count,
                );
            }
        }

        for (const out of variant.adult.outputs) {
            if (out.kind === 'egg') {
                const cur = eggs.get(out.element_id) ?? {
                    element_id: out.element_id,
                    label: out.label,
                    icon: out.icon,
                    url: out.url,
                    amount_per_cycle: 0,
                };
                cur.amount_per_cycle += out.amount_per_cycle * row.count;
                eggs.set(out.element_id, cur);
            } else {
                bump(
                    {
                        element_id: out.element_id,
                        name: out.label,
                        icon: out.icon,
                        url: out.url,
                    } as ElementRefData,
                    out.amount_per_cycle * row.count,
                );
            }
        }
    }

    return {
        elements: [...net.values()].sort(
            (a, b) => Math.abs(b.net_per_cycle) - Math.abs(a.net_per_cycle),
        ),
        eggs: [...eggs.values()],
    };
}

export function encodeRows(rows: RanchRow[]): string {
    return rows
        .filter((r) => r.count > 0)
        .map((r) => `${r.critterId}.${r.foodElementId ?? ''}:${r.count}`)
        .join(',');
}

export function decodeRows(value: string): RanchRow[] {
    if (!value) return [];
    return value
        .split(',')
        .map((token) => {
            const colonIdx = token.lastIndexOf(':');
            const idPart = colonIdx >= 0 ? token.slice(0, colonIdx) : token;
            const countPart =
                colonIdx >= 0 ? token.slice(colonIdx + 1) : '0';
            const dotIdx = idPart.indexOf('.');
            const critterId =
                dotIdx >= 0 ? idPart.slice(0, dotIdx) : idPart;
            const foodRaw = dotIdx >= 0 ? idPart.slice(dotIdx + 1) : '';
            const count = Number.parseInt(countPart, 10);
            return {
                critterId,
                foodElementId: foodRaw.length > 0 ? foodRaw : null,
                count: Number.isFinite(count) ? count : 0,
            } satisfies RanchRow;
        })
        .filter((r) => r.critterId.length > 0);
}
// endregion

// region Reactive composable
/** Reactive planner state backed by the URL query (?r=...). */
export function useRanch(catalog: RanchCatalog, initialQuery: string) {
    const rows: Ref<RanchRow[]> = ref(
        decodeRows(initialQuery).filter(
            (r) => r.count > 0 && findVariant(catalog, r.critterId) !== null,
        ),
    );

    const balance = computed(() => computeBalance(catalog, rows.value));

    function syncUrl(): void {
        if (typeof window === 'undefined') return;
        const encoded = encodeRows(rows.value);
        const url = new URL(window.location.href);
        if (encoded) {
            url.searchParams.set('r', encoded);
        } else {
            url.searchParams.delete('r');
        }
        window.history.replaceState(window.history.state, '', url);
    }

    function addCritter(critterId: string): void {
        const variant = findVariant(catalog, critterId);
        if (!variant) return;
        rows.value.push({
            critterId,
            foodElementId:
                variant.adult.diets[0]?.consumed.element_id ?? null,
            count: 1,
        });
        syncUrl();
    }

    function removeRow(index: number): void {
        rows.value.splice(index, 1);
        syncUrl();
    }

    function setCount(index: number, count: number): void {
        const row = rows.value[index];
        if (row) row.count = Math.max(0, count);
        syncUrl();
    }

    function setFood(index: number, foodElementId: string | null): void {
        const row = rows.value[index];
        if (row) row.foodElementId = foodElementId;
        syncUrl();
    }

    return { rows, balance, addCritter, removeRow, setCount, setFood };
}
// endregion
