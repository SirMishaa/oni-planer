<script setup lang="ts">
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui';
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import type { ElementData } from '@/types/App/Data/data';

defineOptions({ layout: AppLayout });

type ElementState = 'solid' | 'liquid' | 'gas' | 'special';
type ActiveState = 'all' | ElementState;

const props = defineProps<{ elements: ElementData[] }>();

const search = ref('');
const activeState = ref<ActiveState>('all');

const STATES: ElementState[] = ['solid', 'liquid', 'gas', 'special'];

const stateMonogram: Record<ElementState, { bg: string; text: string }> = {
    solid: { bg: 'bg-state-solid', text: 'text-[oklch(16%_0.06_52)]' },
    liquid: { bg: 'bg-state-liquid', text: 'text-[oklch(98%_0.01_222)]' },
    gas: { bg: 'bg-state-gas', text: 'text-[oklch(16%_0.06_142)]' },
    special: { bg: 'bg-state-special', text: 'text-[oklch(98%_0.01_290)]' },
};

const stateFilterActive: Record<ElementState, string> = {
    solid: 'bg-state-solid text-[oklch(16%_0.06_52)]',
    liquid: 'bg-state-liquid text-[oklch(98%_0.01_222)]',
    gas: 'bg-state-gas text-[oklch(16%_0.06_142)]',
    special: 'bg-state-special text-[oklch(98%_0.01_290)]',
};

const stateCounts = computed(() => {
    const counts: Record<ElementState, number> = {
        solid: 0,
        liquid: 0,
        gas: 0,
        special: 0,
    };
    for (const el of props.elements) {
        const s = toState(el.state);
        counts[s]++;
    }
    return counts;
});

const filtered = computed<ElementData[]>(() => {
    const q = search.value.toLowerCase();
    return props.elements.filter((el) => {
        const matchState =
            activeState.value === 'all' || el.state === activeState.value;
        const matchSearch =
            !q ||
            el.element_id.toLowerCase().includes(q) ||
            Object.values(el.name).some((n) => n.toLowerCase().includes(q));
        return matchState && matchSearch;
    });
});

function toState(state: string): ElementState {
    const valid: ElementState[] = ['solid', 'liquid', 'gas', 'special'];
    return valid.includes(state as ElementState)
        ? (state as ElementState)
        : 'special';
}

function toSlug(elementId: string): string {
    return elementId.replace(/([A-Z])/g, (c, _, i) => (i > 0 ? '-' : '') + c.toLowerCase());
}

function displayName(el: ElementData): string {
    return el.name['en'] ?? el.element_id;
}

function initials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0] ?? '')
        .join('')
        .toUpperCase();
}

function onStateChange(val: unknown): void {
    if (!val || typeof val !== 'string') return;
    activeState.value = val as ActiveState;
}

const ELEMENTS_WITHOUT_ICON = new Set(['COMPOSITION', 'Creature', 'Vacuum', 'Void']);

function hasIcon(elementId: string): boolean {
    return !ELEMENTS_WITHOUT_ICON.has(elementId);
}
</script>

<template>
    <div class="min-h-full pb-16 md:pb-0">
        <!-- Sticky toolbar -->
        <header
            class="sticky top-0 z-10 border-b border-[oklch(82%_0.025_75)] bg-[oklch(94%_0.015_75)]/90 backdrop-blur-sm dark:border-[oklch(32%_0.020_250)] dark:bg-[oklch(22%_0.016_250)]/95"
        >
            <div class="mx-auto max-w-3xl px-5 py-4">
                <div class="flex items-baseline justify-between">
                    <h1 class="text-base font-semibold tracking-tight">
                        Éléments
                    </h1>
                    <span
                        class="text-xs text-[oklch(42%_0.015_250)] tabular-nums dark:text-[oklch(66%_0.012_260)]"
                    >
                        {{ filtered.length
                        }}<span v-if="filtered.length !== elements.length">
                            / {{ elements.length }}</span
                        >
                    </span>
                </div>

                <!-- Search -->
                <input
                    v-model="search"
                    type="search"
                    placeholder="Rechercher un élément…"
                    class="mt-2.5 w-full rounded-lg border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] px-3 py-2 text-sm placeholder:text-[oklch(58%_0.01_250)] focus:border-state-liquid focus:ring-1 focus:ring-state-liquid focus:outline-none dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(18%_0.015_260)] dark:placeholder:text-[oklch(52%_0.012_260)]"
                />

                <!-- State filters — Reka UI ToggleGroup (single, always-selected) -->
                <ToggleGroupRoot
                    type="single"
                    :model-value="activeState"
                    class="mt-2.5 flex flex-wrap gap-1.5"
                    @update:model-value="onStateChange"
                >
                    <ToggleGroupItem
                        value="all"
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-state-liquid focus-visible:outline-none data-[state=off]:text-[oklch(42%_0.015_250)] data-[state=off]:hover:bg-[oklch(90%_0.01_250)] data-[state=on]:bg-[oklch(16%_0.012_250)] data-[state=on]:text-[oklch(97%_0.005_260)] dark:data-[state=off]:text-[oklch(66%_0.012_260)] dark:data-[state=off]:hover:bg-[oklch(30%_0.015_260)] dark:data-[state=on]:bg-[oklch(88%_0.008_260)] dark:data-[state=on]:text-[oklch(14%_0.01_260)]"
                    >
                        Tous
                        <span class="ml-1 opacity-50">{{
                            elements.length
                        }}</span>
                    </ToggleGroupItem>

                    <ToggleGroupItem
                        v-for="state in STATES"
                        :key="state"
                        :value="state"
                        class="rounded px-2.5 py-1 text-xs font-medium capitalize transition-colors focus-visible:ring-2 focus-visible:ring-state-liquid focus-visible:outline-none data-[state=off]:text-[oklch(42%_0.015_250)] data-[state=off]:hover:bg-[oklch(90%_0.01_250)] dark:data-[state=off]:text-[oklch(66%_0.012_260)] dark:data-[state=off]:hover:bg-[oklch(30%_0.015_260)]"
                        :class="
                            activeState === state
                                ? stateFilterActive[state]
                                : ''
                        "
                    >
                        {{ state }}
                        <span class="ml-1 opacity-50">{{
                            stateCounts[state]
                        }}</span>
                    </ToggleGroupItem>
                </ToggleGroupRoot>
            </div>
        </header>

        <!-- Element list -->
        <main class="mx-auto max-w-3xl px-5 py-3">
            <p
                v-if="filtered.length === 0"
                class="mt-24 text-center text-sm text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
            >
                Aucun élément trouvé.
            </p>

            <ul
                v-else
                class="divide-y divide-[oklch(90%_0.008_250)] dark:divide-[oklch(30%_0.012_260)]"
            >
                <li
                    v-for="el in filtered"
                    :key="el.element_id"
                >
                <Link
                    :href="`/elements/${toSlug(el.element_id)}`"
                    class="-mx-3 flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-[oklch(91%_0.009_250)] dark:hover:bg-[oklch(28%_0.015_260)]"
                >
                    <!-- Element icon -->
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg overflow-hidden select-none"
                        :class="[
                            stateMonogram[toState(el.state)].bg,
                            stateMonogram[toState(el.state)].text,
                        ]"
                    >
                        <img
                            v-if="hasIcon(el.element_id)"
                            :src="`/assets/game/elements/${el.element_id}.webp`"
                            :alt="displayName(el)"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <span v-else class="text-[10px] font-bold">{{ initials(displayName(el)) }}</span>
                    </div>

                    <!-- Name + id -->
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm leading-none font-medium">
                            {{ displayName(el) }}
                        </p>
                        <p
                            class="mt-0.5 font-mono text-[11px] text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                        >
                            {{ el.element_id }}
                        </p>
                    </div>

                    <!-- Category + state badge -->
                    <div class="flex shrink-0 items-center gap-2">
                        <span
                            class="hidden text-xs text-[oklch(42%_0.015_250)] sm:block dark:text-[oklch(66%_0.012_260)]"
                        >
                            {{ el.material_category }}
                        </span>
                        <Badge :variant="toState(el.state)">{{
                            el.state
                        }}</Badge>
                    </div>
                </Link>
                </li>
            </ul>
        </main>
    </div>
</template>
