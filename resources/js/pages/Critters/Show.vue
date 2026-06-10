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
    <div class="mx-auto max-w-5xl px-4 py-6">
        <Link href="/critters" class="text-sm opacity-60 hover:underline">← Créatures</Link>

        <!-- region Hero -->
        <header class="mt-3 flex flex-wrap items-center gap-5">
            <div class="flex h-32 w-32 items-center justify-center rounded-2xl bg-black/[0.03] dark:bg-white/[0.03]">
                <img v-if="variant.image" :src="variant.image" :alt="family.name" class="h-28 w-28 object-contain" />
                <span v-else class="text-5xl opacity-30">🐾</span>
            </div>
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold">{{ family.name }}</h1>
                <p v-if="family.description" class="mt-1 max-w-prose text-sm opacity-70">{{ family.description['en'] }}</p>
            </div>
            <button type="button" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500" @click="addToRanch">
                + Ajouter à l'élevage
            </button>
        </header>
        <!-- endregion -->

        <!-- region Variant tabs -->
        <div v-if="family.variants.length > 1" class="mt-5 flex flex-wrap gap-2">
            <button
                v-for="(v, i) in family.variants"
                :key="v.critter_id"
                type="button"
                class="rounded-full px-3 py-1 text-sm ring-1 ring-inset"
                :class="i === selectedIndex ? 'bg-emerald-600 text-white ring-emerald-600' : 'ring-black/10 hover:bg-black/5 dark:ring-white/10 dark:hover:bg-white/5'"
                @click="selectedIndex = i"
            >
                {{ nameEn(v.name, v.critter_id) }}
            </button>
        </div>
        <!-- endregion -->

        <!-- region Panels -->
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <section class="rounded-xl border border-black/5 p-4 dark:border-white/5">
                <h2 class="mb-2 text-sm font-semibold uppercase opacity-60">Stats</h2>
                <dl class="grid grid-cols-2 gap-1 text-sm">
                    <dt class="opacity-60">Température</dt><dd>{{ tempC(adult.min_temp) }} – {{ tempC(adult.max_temp) }}</dd>
                    <dt class="opacity-60">Calories/cycle</dt><dd>{{ adult.calories_per_cycle.toLocaleString() }}</dd>
                    <dt class="opacity-60">Surpopulation</dt><dd>{{ adult.overcrowding_threshold }} tuiles</dd>
                    <dt class="opacity-60">Durée de vie</dt><dd>{{ lifespanLabel(adult.lifespan) }}</dd>
                    <dt class="opacity-60">Incubation</dt><dd>{{ adult.incubation_time }} cycles</dd>
                </dl>
            </section>

            <section class="rounded-xl border border-black/5 p-4 dark:border-white/5">
                <h2 class="mb-2 text-sm font-semibold uppercase opacity-60">Régime</h2>
                <p v-if="adult.diets.length === 0" class="text-sm opacity-60">Données de régime indisponibles.</p>
                <ul v-else class="space-y-1.5 text-sm">
                    <li v-for="d in adult.diets" :key="d.consumed.element_id" class="flex items-center gap-2">
                        <img v-if="d.consumed.icon" :src="d.consumed.icon" alt="" class="h-5 w-5 object-contain" />
                        <component :is="d.consumed.url ? 'a' : 'span'" :href="d.consumed.url ?? undefined" :class="d.consumed.url ? 'hover:underline' : ''">{{ d.consumed.name }}</component>
                        <span class="opacity-50">→</span>
                        <template v-if="d.produced">
                            <img v-if="d.produced.icon" :src="d.produced.icon" alt="" class="h-5 w-5 object-contain" />
                            <component :is="d.produced.url ? 'a' : 'span'" :href="d.produced.url ?? undefined" :class="d.produced.url ? 'hover:underline' : ''">{{ d.produced.name }}</component>
                        </template>
                        <span class="ml-auto tabular-nums opacity-70">{{ d.amount_per_cycle }} kg/cy</span>
                    </li>
                </ul>
            </section>

            <section class="rounded-xl border border-black/5 p-4 dark:border-white/5 sm:col-span-2">
                <h2 class="mb-2 text-sm font-semibold uppercase opacity-60">Production</h2>
                <ul class="space-y-1.5 text-sm">
                    <li v-for="o in adult.outputs" :key="o.element_id" class="flex items-center gap-2">
                        <img v-if="o.icon" :src="o.icon" alt="" class="h-5 w-5 object-contain" />
                        <component :is="o.url ? 'a' : 'span'" :href="o.url ?? undefined" :class="o.url ? 'hover:underline' : ''">{{ o.label }}</component>
                        <span class="ml-auto tabular-nums opacity-70">{{ o.amount_per_cycle.toFixed(3) }} /cy</span>
                    </li>
                    <li v-if="adult.outputs.length === 0" class="opacity-60">—</li>
                </ul>
            </section>
        </div>
        <!-- endregion -->
    </div>
</template>
