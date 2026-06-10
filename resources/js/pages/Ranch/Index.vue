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
const matches = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return [];
    return allVariants.value.filter((x) => x.variant.critter_id.toLowerCase().includes(q)).slice(0, 8);
});

function variantFor(critterId: string) {
    return allVariants.value.find((x) => x.variant.critter_id === critterId)?.variant ?? null;
}
function nameEn(name: Record<string, string>, fallback: string): string {
    return name['en'] ?? fallback;
}
function fmt(n: number): string {
    return (n > 0 ? '+' : '') + n.toFixed(2).replace(/\.00$/, '');
}
// endregion
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-6">
        <h1 class="mb-4 text-2xl font-semibold">Planificateur d'élevage</h1>

        <div class="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
            <!-- region Rows -->
            <section>
                <div class="relative mb-3">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Ajouter une créature…"
                        class="w-full rounded-lg border border-black/10 px-3 py-2 dark:border-white/10 dark:bg-zinc-900"
                    />
                    <ul v-if="matches.length" class="absolute z-10 mt-1 w-full rounded-lg border border-black/10 bg-white shadow dark:border-white/10 dark:bg-zinc-900">
                        <li v-for="m in matches" :key="m.variant.critter_id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-black/5 dark:hover:bg-white/5"
                                @click="addCritter(m.variant.critter_id); search = ''"
                            >
                                <img v-if="m.variant.image" :src="m.variant.image" alt="" class="h-8 w-8 object-contain" />
                                <span>{{ nameEn(m.variant.name, m.variant.critter_id) }}</span>
                                <span class="ml-auto text-xs opacity-60">{{ m.family.name }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <p v-if="rows.length === 0" class="rounded-lg border border-dashed border-black/15 p-6 text-center text-sm opacity-70 dark:border-white/15">
                    Ajoute une créature pour démarrer ton élevage.
                </p>

                <ul class="space-y-2">
                    <li
                        v-for="(row, i) in rows"
                        :key="i"
                        class="flex flex-wrap items-center gap-2 rounded-xl border border-black/5 p-3 ring-1 ring-white/30 ring-inset dark:border-white/5 dark:bg-zinc-900/40"
                    >
                        <img v-if="variantFor(row.critterId)?.image" :src="variantFor(row.critterId)!.image!" alt="" class="h-12 w-12 object-contain" />
                        <span class="font-medium">{{ nameEn(variantFor(row.critterId)?.name ?? {}, row.critterId) }}</span>

                        <select
                            v-if="(variantFor(row.critterId)?.adult.diets.length ?? 0) > 1"
                            class="rounded border border-black/10 px-2 py-1 text-sm dark:border-white/10 dark:bg-zinc-800"
                            :value="row.foodElementId ?? ''"
                            @change="setFood(i, ($event.target as HTMLSelectElement).value || null)"
                        >
                            <option v-for="d in variantFor(row.critterId)!.adult.diets" :key="d.consumed.element_id" :value="d.consumed.element_id">
                                {{ d.consumed.name }}
                            </option>
                        </select>
                        <span v-else-if="(variantFor(row.critterId)?.adult.diets.length ?? 0) === 0" class="rounded bg-amber-500/15 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-300">
                            régime indisponible
                        </span>

                        <div class="ml-auto flex items-center gap-1">
                            <button type="button" class="h-7 w-7 rounded border border-black/10 dark:border-white/10" @click="setCount(i, row.count - 1)">−</button>
                            <input
                                type="number"
                                min="0"
                                class="w-14 rounded border border-black/10 px-2 py-1 text-center dark:border-white/10 dark:bg-zinc-800"
                                :value="row.count"
                                @input="setCount(i, Number.parseInt(($event.target as HTMLInputElement).value || '0', 10))"
                            />
                            <button type="button" class="h-7 w-7 rounded border border-black/10 dark:border-white/10" @click="setCount(i, row.count + 1)">+</button>
                            <button type="button" class="ml-2 text-sm text-red-500" @click="removeRow(i)">✕</button>
                        </div>
                    </li>
                </ul>
            </section>
            <!-- endregion -->

            <!-- region Summary -->
            <aside class="lg:sticky lg:top-6 lg:self-start">
                <div class="rounded-xl border border-black/5 p-4 ring-1 ring-white/30 ring-inset dark:border-white/5 dark:bg-zinc-900/40">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide opacity-70">Bilan / cycle</h2>

                    <ul class="space-y-1.5">
                        <li v-for="el in balance.elements" :key="el.element_id" class="flex items-center gap-2 text-sm">
                            <img v-if="el.icon" :src="el.icon" alt="" class="h-5 w-5 object-contain" />
                            <component :is="el.url ? 'a' : 'span'" :href="el.url ?? undefined" class="truncate" :class="el.url ? 'hover:underline' : ''">
                                {{ el.name }}
                            </component>
                            <span class="ml-auto tabular-nums" :class="el.net_per_cycle >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'">
                                {{ fmt(el.net_per_cycle) }} kg
                            </span>
                        </li>
                    </ul>

                    <div v-if="balance.eggs.length" class="mt-4 border-t border-black/5 pt-3 dark:border-white/5">
                        <h3 class="mb-1.5 text-xs font-semibold uppercase opacity-60">Œufs</h3>
                        <ul class="space-y-1 text-sm">
                            <li v-for="egg in balance.eggs" :key="egg.element_id" class="flex items-center gap-2">
                                <img v-if="egg.icon" :src="egg.icon" alt="" class="h-5 w-5 object-contain" />
                                <span class="truncate">{{ egg.label }}</span>
                                <span class="ml-auto tabular-nums">+{{ egg.amount_per_cycle.toFixed(3) }}/cy</span>
                            </li>
                        </ul>
                    </div>

                    <p v-if="!balance.elements.length && !balance.eggs.length" class="text-sm opacity-60">Aucune créature ajoutée.</p>
                </div>
            </aside>
            <!-- endregion -->
        </div>
    </div>
</template>
