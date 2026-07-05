<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { CritterFamilyData, CritterVariantData } from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

const props = defineProps<{ family: CritterFamilyData }>();

// region state
const selectedIndex = ref(0);
const variant = computed((): CritterVariantData => props.family.variants[selectedIndex.value] ?? props.family.variants[0]!);
const adult = computed(() => variant.value.adult);

function nameEn(name: Record<string, string>, fallback: string): string {
    return name['en'] ?? fallback;
}

function tempC(k: number): string {
    return `${(k - 273.15).toFixed(0)} °C`;
}

function lifespanLabel(n: number): string {
    return n > 0 ? `${n} cycles` : '—';
}

function addToRanch(): void {
    router.get('/ranch', { r: `${variant.value.critter_id}.:1` });
}
// endregion
</script>

<template>
    <div class="mx-auto max-w-5xl px-4 py-6 pb-24 md:pb-6">
        <!-- Back link -->
        <Link
            href="/critters"
            class="inline-flex items-center gap-1.5 text-sm text-[oklch(48%_0.015_250)] transition-colors hover:text-[oklch(28%_0.02_60)] dark:text-[oklch(58%_0.012_260)] dark:hover:text-[oklch(88%_0.012_65)]"
        >
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                <path d="M9 2L4 7l5 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Créatures
        </Link>

        <!-- region Hero -->
        <header class="mt-4 flex flex-wrap items-start gap-5 sm:flex-nowrap">
            <!-- Critter portrait -->
            <div class="flex h-36 w-36 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-[oklch(88%_0.01_250)] bg-[oklch(97%_0.02_290)] shadow-sm dark:border-[oklch(32%_0.015_260)] dark:bg-[oklch(22%_0.025_290)]">
                <img
                    v-if="variant.image"
                    :src="variant.image"
                    :alt="family.name"
                    class="h-28 w-28 object-contain transition-transform duration-300 hover:scale-105"
                />
                <span v-else class="text-5xl opacity-25">🐾</span>
            </div>

            <!-- Name + description + CTA -->
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-[oklch(14%_0.025_60)] dark:text-[oklch(95%_0.012_65)]">
                            {{ family.name }}
                        </h1>
                        <p
                            v-if="family.variants.length > 1"
                            class="mt-0.5 text-xs text-[oklch(52%_0.015_250)] dark:text-[oklch(60%_0.012_260)]"
                        >
                            {{ family.variants.length }} variantes
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex shrink-0 items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all hover:bg-emerald-500 hover:-translate-y-px hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 active:scale-[0.97]"
                        @click="addToRanch"
                    >
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                            <path d="M6 2v8M2 6h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                        </svg>
                        Ajouter à l'élevage
                    </button>
                </div>
                <p
                    v-if="family.description?.['en'] ?? ''"
                    class="mt-3 max-w-prose text-sm leading-relaxed text-[oklch(38%_0.015_250)] dark:text-[oklch(68%_0.010_260)]"
                >
                    {{ family.description?.['en'] ?? '' }}
                </p>
            </div>
        </header>
        <!-- endregion -->

        <!-- region Variant tabs -->
        <div v-if="family.variants.length > 1" class="mt-5 flex flex-wrap gap-2">
            <button
                v-for="(v, i) in family.variants"
                :key="v.critter_id"
                type="button"
                class="rounded-full px-3.5 py-1.5 text-sm font-medium ring-1 ring-inset transition-all"
                :class="
                    i === selectedIndex
                        ? 'bg-emerald-600 text-white ring-emerald-600 shadow-sm'
                        : 'text-[oklch(32%_0.015_250)] ring-[oklch(84%_0.01_250)] hover:bg-[oklch(0%_0_0/0.05)] hover:ring-[oklch(74%_0.012_250)] focus-visible:outline-none focus-visible:ring-oni-amber dark:text-[oklch(78%_0.012_260)] dark:ring-[oklch(35%_0.015_260)] dark:hover:bg-[oklch(100%_0_0/0.06)]'
                "
                @click="selectedIndex = i"
            >
                {{ nameEn(v.name, v.critter_id) }}
            </button>
        </div>
        <!-- endregion -->

        <!-- region Panels -->
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <!-- Stats panel -->
            <section class="rounded-xl border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] p-4 ring-1 ring-white/40 ring-inset dark:border-[oklch(32%_0.015_260)] dark:bg-[oklch(20%_0.016_250)]/70 dark:ring-white/5">
                <h2 class="mb-3 text-[10px] font-bold tracking-[0.12em] text-[oklch(42%_0.015_250)] uppercase dark:text-[oklch(62%_0.012_260)]">
                    Stats
                </h2>
                <dl class="divide-y divide-[oklch(91%_0.007_250)] text-sm dark:divide-[oklch(28%_0.012_260)]">
                    <div class="flex items-center justify-between gap-2 py-2">
                        <dt class="text-[oklch(48%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">Température</dt>
                        <dd class="tabular-nums font-medium text-[oklch(22%_0.02_60)] dark:text-[oklch(88%_0.01_65)]">
                            {{ tempC(adult.min_temp) }} – {{ tempC(adult.max_temp) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 py-2">
                        <dt class="text-[oklch(48%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">Calories/cycle</dt>
                        <dd class="tabular-nums font-medium text-[oklch(22%_0.02_60)] dark:text-[oklch(88%_0.01_65)]">
                            {{ adult.calories_per_cycle.toLocaleString() }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 py-2">
                        <dt class="text-[oklch(48%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">Surpopulation</dt>
                        <dd class="tabular-nums font-medium text-[oklch(22%_0.02_60)] dark:text-[oklch(88%_0.01_65)]">
                            {{ adult.overcrowding_threshold }} tuiles
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 py-2">
                        <dt class="text-[oklch(48%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">Durée de vie</dt>
                        <dd class="tabular-nums font-medium text-[oklch(22%_0.02_60)] dark:text-[oklch(88%_0.01_65)]">
                            {{ lifespanLabel(adult.lifespan) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 py-2">
                        <dt class="text-[oklch(48%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">Incubation</dt>
                        <dd class="tabular-nums font-medium text-[oklch(22%_0.02_60)] dark:text-[oklch(88%_0.01_65)]">
                            {{ adult.incubation_time }} cycles
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Diet panel -->
            <section class="rounded-xl border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] p-4 ring-1 ring-white/40 ring-inset dark:border-[oklch(32%_0.015_260)] dark:bg-[oklch(20%_0.016_250)]/70 dark:ring-white/5">
                <h2 class="mb-3 text-[10px] font-bold tracking-[0.12em] text-[oklch(42%_0.015_250)] uppercase dark:text-[oklch(62%_0.012_260)]">
                    Régime
                </h2>
                <p v-if="adult.diets.length === 0" class="text-sm text-[oklch(52%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">
                    Données de régime indisponibles.
                </p>
                <ul v-else class="space-y-2">
                    <li
                        v-for="d in adult.diets"
                        :key="d.consumed.element_id"
                        class="flex items-center gap-2 rounded-lg bg-[oklch(0%_0_0/0.03)] px-2.5 py-2 text-sm dark:bg-[oklch(100%_0_0/0.04)]"
                    >
                        <img v-if="d.consumed.icon" :src="d.consumed.icon" alt="" class="h-5 w-5 shrink-0 object-contain" />
                        <component
                            :is="d.consumed.url ? 'a' : 'span'"
                            :href="d.consumed.url ?? undefined"
                            class="min-w-0 flex-1 truncate font-medium text-[oklch(22%_0.025_60)] dark:text-[oklch(88%_0.012_65)]"
                            :class="d.consumed.url ? 'hover:underline' : ''"
                        >
                            {{ d.consumed.name }}
                        </component>
                        <template v-if="d.produced">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" class="shrink-0 text-[oklch(60%_0.01_250)] dark:text-[oklch(50%_0.01_260)]">
                                <path d="M2 6h8M7 3l3 3-3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <img v-if="d.produced.icon" :src="d.produced.icon" alt="" class="h-5 w-5 shrink-0 object-contain" />
                            <component
                                :is="d.produced.url ? 'a' : 'span'"
                                :href="d.produced.url ?? undefined"
                                class="truncate text-[oklch(30%_0.02_60)] dark:text-[oklch(82%_0.01_65)]"
                                :class="d.produced.url ? 'hover:underline' : ''"
                            >
                                {{ d.produced.name }}
                            </component>
                        </template>
                        <span class="ml-auto shrink-0 tabular-nums text-xs text-[oklch(48%_0.012_250)] dark:text-[oklch(60%_0.01_260)]">
                            {{ d.amount_per_cycle }} kg/cy
                        </span>
                    </li>
                </ul>
            </section>

            <!-- Production panel (full width) -->
            <section class="rounded-xl border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] p-4 ring-1 ring-white/40 ring-inset dark:border-[oklch(32%_0.015_260)] dark:bg-[oklch(20%_0.016_250)]/70 dark:ring-white/5 sm:col-span-2">
                <h2 class="mb-3 text-[10px] font-bold tracking-[0.12em] text-[oklch(42%_0.015_250)] uppercase dark:text-[oklch(62%_0.012_260)]">
                    Production
                </h2>
                <p v-if="adult.outputs.length === 0" class="text-sm text-[oklch(52%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">—</p>
                <ul v-else class="flex flex-wrap gap-2">
                    <li
                        v-for="o in adult.outputs"
                        :key="o.element_id"
                        class="flex items-center gap-2 rounded-lg bg-[oklch(0%_0_0/0.03)] px-2.5 py-2 text-sm dark:bg-[oklch(100%_0_0/0.04)]"
                    >
                        <img v-if="o.icon" :src="o.icon" alt="" class="h-5 w-5 shrink-0 object-contain" />
                        <component
                            :is="o.url ? 'a' : 'span'"
                            :href="o.url ?? undefined"
                            class="font-medium text-[oklch(22%_0.025_60)] dark:text-[oklch(88%_0.012_65)]"
                            :class="o.url ? 'hover:underline' : ''"
                        >
                            {{ o.label }}
                        </component>
                        <span class="tabular-nums text-xs text-[oklch(48%_0.012_250)] dark:text-[oklch(60%_0.01_260)]">
                            {{ o.amount_per_cycle.toFixed(3) }}/cy
                        </span>
                    </li>
                </ul>
            </section>
        </div>
        <!-- endregion -->
    </div>
</template>
