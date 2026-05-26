<script setup lang="ts">
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BuildingData } from '@/types/App/Data/data';

defineOptions({ layout: AppLayout });

const props = defineProps<{ buildings: BuildingData[] }>();

const search = ref('');
const activeCategory = ref<string>('all');

const categories = computed<string[]>(() => {
    const cats = new Set(props.buildings.map((b) => b.category));
    return Array.from(cats).sort();
});

const categoryCounts = computed<Record<string, number>>(() => {
    const counts: Record<string, number> = {};
    for (const b of props.buildings) {
        counts[b.category] = (counts[b.category] ?? 0) + 1;
    }
    return counts;
});

const filtered = computed<BuildingData[]>(() => {
    const q = search.value.toLowerCase();
    return props.buildings.filter((b) => {
        const matchCat =
            activeCategory.value === 'all' || b.category === activeCategory.value;
        const matchSearch =
            !q ||
            b.building_id.toLowerCase().includes(q) ||
            Object.values(b.name).some((n) => n.toLowerCase().includes(q));
        return matchCat && matchSearch;
    });
});

function displayName(b: BuildingData): string {
    return b.name['en'] ?? b.building_id;
}

function powerLabel(b: BuildingData): string | null {
    if (b.power_generation && b.power_generation > 0) {
        return `+${b.power_generation} W`;
    }
    if (b.power_consumption && b.power_consumption > 0) {
        return `-${b.power_consumption} W`;
    }
    return null;
}

function powerClass(b: BuildingData): string {
    if (b.power_generation && b.power_generation > 0) {
        return 'text-[oklch(60%_0.14_148)]';
    }
    return 'text-[oklch(58%_0.12_30)]';
}
</script>

<template>
    <div class="min-h-full pb-16 md:pb-0">
        <!-- region Sticky toolbar -->
        <header
            class="sticky top-0 z-10 border-b border-[oklch(82%_0.025_75)] bg-[oklch(94%_0.015_75)]/90 backdrop-blur-sm dark:border-[oklch(32%_0.020_250)] dark:bg-[oklch(22%_0.016_250)]/95"
        >
            <div class="mx-auto max-w-3xl px-5 py-4">
                <div class="flex items-baseline justify-between">
                    <h1 class="text-base font-semibold tracking-tight">
                        Bâtiments
                    </h1>
                    <span
                        class="text-xs text-[oklch(42%_0.015_250)] tabular-nums dark:text-[oklch(66%_0.012_260)]"
                    >
                        {{ filtered.length
                        }}<span v-if="filtered.length !== buildings.length">
                            / {{ buildings.length }}</span
                        >
                    </span>
                </div>

                <!-- Search -->
                <input
                    v-model="search"
                    type="search"
                    placeholder="Rechercher un bâtiment…"
                    class="mt-2.5 w-full rounded-lg border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] px-3 py-2 text-sm placeholder:text-[oklch(58%_0.01_250)] focus:border-oni-amber focus:ring-1 focus:ring-oni-amber focus:outline-none dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(18%_0.015_260)] dark:placeholder:text-[oklch(52%_0.012_260)]"
                />

                <!-- Category filters -->
                <div class="mt-2.5 flex flex-wrap gap-1.5">
                    <button
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                        :class="
                            activeCategory === 'all'
                                ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                        "
                        @click="activeCategory = 'all'"
                    >
                        Tous
                        <span class="ml-1 opacity-50">{{ buildings.length }}</span>
                    </button>

                    <button
                        v-for="cat in categories"
                        :key="cat"
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                        :class="
                            activeCategory === cat
                                ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                        "
                        @click="activeCategory = cat"
                    >
                        {{ cat }}
                        <span class="ml-1 opacity-50">{{ categoryCounts[cat] }}</span>
                    </button>
                </div>
            </div>
        </header>
        <!-- endregion -->

        <!-- region Building list -->
        <main class="mx-auto max-w-3xl px-5 py-3">
            <p
                v-if="filtered.length === 0"
                class="mt-24 text-center text-sm text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
            >
                Aucun bâtiment trouvé.
            </p>

            <ul
                v-else
                class="divide-y divide-[oklch(90%_0.008_250)] dark:divide-[oklch(30%_0.012_260)]"
            >
                <li v-for="b in filtered" :key="b.building_id">
                    <div
                        class="-mx-3 flex items-center gap-3 rounded-lg px-3 py-2.5"
                    >
                        <!-- Building icon -->
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[oklch(20%_0.025_250)] dark:bg-[oklch(16%_0.018_260)]"
                        >
                            <img
                                :src="`/assets/game/buildings/${b.building_id}.webp`"
                                :alt="displayName(b)"
                                class="h-full w-full object-contain"
                                loading="lazy"
                            />
                        </div>

                        <!-- Name + id -->
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm leading-none font-medium">
                                {{ displayName(b) }}
                            </p>
                            <p
                                class="mt-0.5 font-mono text-[11px] text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                            >
                                {{ b.building_id }}
                            </p>
                        </div>

                        <!-- Size + power -->
                        <div class="flex shrink-0 items-center gap-3">
                            <span
                                class="hidden text-xs tabular-nums text-[oklch(42%_0.015_250)] sm:block dark:text-[oklch(66%_0.012_260)]"
                            >
                                {{ b.width }}×{{ b.height }}
                            </span>
                            <span
                                v-if="powerLabel(b)"
                                class="text-xs font-medium tabular-nums"
                                :class="powerClass(b)"
                            >
                                {{ powerLabel(b) }}
                            </span>
                        </div>
                    </div>
                </li>
            </ul>
        </main>
        <!-- endregion -->
    </div>
</template>
