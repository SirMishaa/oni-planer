<script setup lang="ts">
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useRanch, type RanchCatalog } from '@/composables/useRanch';

defineOptions({ layout: AppLayout });

const props = defineProps<{ catalog: RanchCatalog }>();

// region state
const initialQuery = new URLSearchParams(window.location.search).get('r') ?? '';
const { rows, balance, addCritter, removeRow, setCount, setFood } = useRanch(props.catalog, initialQuery);

const search = ref('');

const allVariants = computed(() =>
    props.catalog.families.flatMap((f) => f.variants.map((v) => ({ family: f, variant: v }))),
);

// Memoized lookup map: critter_id → { family, variant }
const variantMap = computed(() => {
    const map = new Map<string, { family: (typeof allVariants.value)[number]['family']; variant: (typeof allVariants.value)[number]['variant'] }>();
    for (const entry of allVariants.value) {
        map.set(entry.variant.critter_id, entry);
    }
    return map;
});

function variantFor(critterId: string) {
    return variantMap.value.get(critterId)?.variant ?? null;
}

function familyFor(critterId: string) {
    return variantMap.value.get(critterId)?.family ?? null;
}

const matches = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return [];
    return allVariants.value
        .filter((x) => {
            const displayName = nameEn(x.variant.name, x.variant.critter_id).toLowerCase();
            return displayName.includes(q) || x.variant.critter_id.toLowerCase().includes(q);
        })
        .slice(0, 8);
});

// Max absolute net value for proportional bar widths
const maxAbsNet = computed(() => {
    const vals = balance.value.elements.map((e) => Math.abs(e.net_per_cycle));
    return Math.max(1, ...vals);
});

function nameEn(name: Record<string, string>, fallback: string): string {
    return name['en'] ?? fallback;
}

function fmt(n: number): string {
    return (n > 0 ? '+' : '') + n.toFixed(2).replace(/\.00$/, '');
}
// endregion
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-6 pb-24 md:pb-6">
        <!-- region Header -->
        <div class="mb-5 flex items-center justify-between">
            <h1 class="text-xl font-bold tracking-tight text-[oklch(18%_0.025_60)] dark:text-[oklch(92%_0.012_65)]">
                Planificateur d'élevage
            </h1>
        </div>
        <!-- endregion -->

        <div class="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
            <!-- region Rows -->
            <section>
                <!-- Search input + dropdown -->
                <div class="relative mb-4">
                    <div class="pointer-events-none absolute inset-y-0 left-3 flex items-center">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" class="text-[oklch(55%_0.01_250)] dark:text-[oklch(52%_0.012_260)]">
                            <circle cx="6" cy="6" r="4" stroke="currentColor" stroke-width="1.3" />
                            <path d="M9 9l3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
                        </svg>
                    </div>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Ajouter une créature…"
                        class="h-10 w-full rounded-lg border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] pl-9 pr-3 text-sm placeholder:text-[oklch(58%_0.01_250)] focus:border-oni-amber focus:ring-1 focus:ring-oni-amber focus:outline-none dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(18%_0.015_260)] dark:placeholder:text-[oklch(52%_0.012_260)] dark:text-[oklch(90%_0.012_65)]"
                    />
                    <ul
                        v-if="matches.length"
                        class="absolute z-10 mt-1.5 w-full overflow-hidden rounded-xl border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] shadow-lg ring-1 ring-black/5 dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(20%_0.016_250)] dark:ring-white/5"
                    >
                        <li v-for="m in matches" :key="m.variant.critter_id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-[oklch(0%_0_0/0.04)] focus-visible:bg-[oklch(0%_0_0/0.04)] focus-visible:outline-none dark:hover:bg-[oklch(100%_0_0/0.06)] dark:focus-visible:bg-[oklch(100%_0_0/0.06)]"
                                @click="addCritter(m.variant.critter_id); search = ''"
                            >
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[oklch(0%_0_0/0.04)] dark:bg-[oklch(100%_0_0/0.05)]">
                                    <img v-if="m.variant.image" :src="m.variant.image" alt="" class="h-7 w-7 object-contain" />
                                    <span v-else class="text-lg opacity-30">🐾</span>
                                </div>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-[oklch(18%_0.025_60)] dark:text-[oklch(90%_0.012_65)]">
                                        {{ nameEn(m.variant.name, m.variant.critter_id) }}
                                    </span>
                                    <span class="block truncate text-xs text-[oklch(52%_0.015_250)] dark:text-[oklch(62%_0.012_260)]">
                                        {{ m.family.name }}
                                    </span>
                                </span>
                                <svg class="shrink-0 text-[oklch(65%_0.01_250)] dark:text-[oklch(55%_0.01_260)]" width="12" height="12" viewBox="0 0 12 12" fill="none">
                                    <path d="M4.5 2.5L8 6l-3.5 3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Empty state -->
                <div
                    v-if="rows.length === 0"
                    class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-[oklch(82%_0.015_250)] bg-[oklch(97%_0.006_260)]/60 px-6 py-12 text-center dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(20%_0.012_260)]/40"
                >
                    <span class="text-4xl opacity-20">🐾</span>
                    <p class="text-sm text-[oklch(48%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">
                        Ajoute une créature pour démarrer ton élevage.
                    </p>
                </div>

                <!-- Critter rows -->
                <ul class="space-y-2">
                    <li
                        v-for="(row, i) in rows"
                        :key="i"
                        class="group flex flex-wrap items-center gap-3 rounded-xl border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] p-3 ring-1 ring-white/40 ring-inset transition-shadow hover:shadow-sm dark:border-[oklch(32%_0.015_260)] dark:bg-[oklch(20%_0.016_250)]/70 dark:ring-white/5"
                    >
                        <!-- Avatar -->
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[oklch(0%_0_0/0.04)] dark:bg-[oklch(100%_0_0/0.05)]">
                            <img
                                v-if="variantFor(row.critterId)?.image"
                                :src="variantFor(row.critterId)!.image!"
                                alt=""
                                class="h-10 w-10 object-contain"
                            />
                            <span v-else class="text-2xl opacity-30">🐾</span>
                        </div>

                        <!-- Name + family -->
                        <div class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-[oklch(18%_0.025_60)] dark:text-[oklch(90%_0.012_65)]">
                                {{ nameEn(variantFor(row.critterId)?.name ?? {}, row.critterId) }}
                            </span>
                            <span class="block truncate text-xs text-[oklch(52%_0.015_250)] dark:text-[oklch(58%_0.012_260)]">
                                {{ familyFor(row.critterId)?.name ?? '' }}
                            </span>
                        </div>

                        <!-- Diet selector -->
                        <select
                            v-if="(variantFor(row.critterId)?.adult.diets.length ?? 0) > 1"
                            class="rounded-lg border border-[oklch(88%_0.01_250)] bg-[oklch(97%_0.005_260)] px-2.5 py-1.5 text-xs transition-colors hover:border-[oklch(78%_0.012_250)] focus:border-oni-amber focus:ring-1 focus:ring-oni-amber focus:outline-none dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(18%_0.015_260)] dark:text-[oklch(88%_0.01_65)]"
                            :value="row.foodElementId ?? ''"
                            @change="setFood(i, ($event.target as HTMLSelectElement).value || null)"
                        >
                            <option
                                v-for="d in variantFor(row.critterId)!.adult.diets"
                                :key="d.consumed.element_id"
                                :value="d.consumed.element_id"
                            >
                                {{ d.consumed.name }}
                            </option>
                        </select>
                        <span
                            v-else-if="(variantFor(row.critterId)?.adult.diets.length ?? 0) === 0"
                            class="rounded-md bg-[oklch(88%_0.12_60)] px-2 py-0.5 text-xs font-medium text-[oklch(38%_0.14_52)] dark:bg-[oklch(30%_0.1_52)] dark:text-[oklch(80%_0.12_60)]"
                        >
                            régime indisponible
                        </span>

                        <!-- Count stepper -->
                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-[oklch(88%_0.01_250)] bg-[oklch(97%_0.005_260)] text-sm font-semibold transition-colors hover:border-[oklch(78%_0.012_250)] hover:bg-[oklch(94%_0.008_250)] active:scale-95 dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(25%_0.015_260)] dark:hover:bg-[oklch(30%_0.015_260)]"
                                @click="setCount(i, row.count - 1)"
                            >
                                −
                            </button>
                            <input
                                type="number"
                                min="0"
                                class="h-8 w-14 rounded-lg border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] px-2 text-center text-sm tabular-nums transition-colors focus:border-oni-amber focus:ring-1 focus:ring-oni-amber focus:outline-none dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(18%_0.015_260)] dark:text-[oklch(90%_0.012_65)]"
                                :value="row.count"
                                @input="setCount(i, Number.parseInt(($event.target as HTMLInputElement).value || '0', 10))"
                            />
                            <button
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-[oklch(88%_0.01_250)] bg-[oklch(97%_0.005_260)] text-sm font-semibold transition-colors hover:border-[oklch(78%_0.012_250)] hover:bg-[oklch(94%_0.008_250)] active:scale-95 dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(25%_0.015_260)] dark:hover:bg-[oklch(30%_0.015_260)]"
                                @click="setCount(i, row.count + 1)"
                            >
                                +
                            </button>
                            <button
                                type="button"
                                class="ml-1 flex h-8 w-8 items-center justify-center rounded-lg text-[oklch(55%_0.12_30)] opacity-0 transition-all hover:bg-[oklch(96%_0.04_30)] active:scale-95 group-hover:opacity-100 dark:text-[oklch(68%_0.10_30)] dark:hover:bg-[oklch(22%_0.06_30)]"
                                @click="removeRow(i)"
                            >
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                                    <path d="M3 3l6 6M9 3l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                                </svg>
                            </button>
                        </div>
                    </li>
                </ul>
            </section>
            <!-- endregion -->

            <!-- region Summary -->
            <aside class="lg:sticky lg:top-6 lg:self-start">
                <div class="overflow-hidden rounded-xl border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] ring-1 ring-white/40 ring-inset dark:border-[oklch(32%_0.015_260)] dark:bg-[oklch(20%_0.016_250)]/70 dark:ring-white/5">
                    <div class="border-b border-[oklch(90%_0.008_250)] px-4 py-3 dark:border-[oklch(30%_0.012_260)]">
                        <h2 class="text-xs font-bold tracking-[0.12em] text-[oklch(42%_0.015_250)] uppercase dark:text-[oklch(62%_0.012_260)]">
                            Bilan / cycle
                        </h2>
                    </div>

                    <!-- Empty summary -->
                    <p
                        v-if="!balance.elements.length && !balance.eggs.length"
                        class="px-4 py-6 text-center text-sm text-[oklch(52%_0.015_250)] dark:text-[oklch(58%_0.012_260)]"
                    >
                        Aucune créature ajoutée.
                    </p>

                    <!-- Element balance bars -->
                    <ul v-if="balance.elements.length" class="divide-y divide-[oklch(92%_0.006_250)] dark:divide-[oklch(28%_0.012_260)]">
                        <li
                            v-for="el in balance.elements"
                            :key="el.element_id"
                            class="relative overflow-hidden px-4 py-2.5"
                        >
                            <!-- Background bar -->
                            <div
                                class="absolute inset-y-0 left-0 transition-all duration-500"
                                :class="el.net_per_cycle >= 0 ? 'bg-emerald-500/10 dark:bg-emerald-500/8' : 'bg-red-500/10 dark:bg-red-500/8'"
                                :style="{ width: `${Math.abs(el.net_per_cycle) / maxAbsNet * 100}%` }"
                            />
                            <!-- Content row -->
                            <div class="relative flex items-center gap-2 text-sm">
                                <img v-if="el.icon" :src="el.icon" alt="" class="h-5 w-5 shrink-0 object-contain" />
                                <component
                                    :is="el.url ? 'a' : 'span'"
                                    :href="el.url ?? undefined"
                                    class="min-w-0 flex-1 truncate"
                                    :class="el.url ? 'hover:underline' : ''"
                                >
                                    {{ el.name }}
                                </component>
                                <span
                                    class="ml-auto shrink-0 tabular-nums font-medium"
                                    :class="el.net_per_cycle >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500 dark:text-red-400'"
                                >
                                    {{ fmt(el.net_per_cycle) }} kg
                                </span>
                            </div>
                        </li>
                    </ul>

                    <!-- Eggs section -->
                    <div v-if="balance.eggs.length" class="border-t border-[oklch(90%_0.008_250)] px-4 py-3 dark:border-[oklch(30%_0.012_260)]">
                        <h3 class="mb-2 text-[10px] font-bold tracking-[0.12em] text-[oklch(52%_0.015_250)] uppercase dark:text-[oklch(58%_0.012_260)]">
                            Œufs
                        </h3>
                        <ul class="space-y-1.5">
                            <li
                                v-for="egg in balance.eggs"
                                :key="egg.element_id"
                                class="flex items-center gap-2 text-sm"
                            >
                                <img v-if="egg.icon" :src="egg.icon" alt="" class="h-5 w-5 shrink-0 object-contain" />
                                <span class="min-w-0 flex-1 truncate text-[oklch(28%_0.02_60)] dark:text-[oklch(82%_0.012_65)]">{{ egg.label }}</span>
                                <span class="ml-auto shrink-0 tabular-nums text-[oklch(45%_0.01_250)] dark:text-[oklch(62%_0.01_260)]">
                                    +{{ egg.amount_per_cycle.toFixed(3) }}/cy
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </aside>
            <!-- endregion -->
        </div>
    </div>
</template>
