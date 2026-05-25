<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ElementData } from '@/types/App/Data/data';

type ElementState = 'solid' | 'liquid' | 'gas' | 'special';
type State = 'all' | ElementState;

const props = defineProps<{
    elements: ElementData[];
}>();

const search = ref('');
const activeState = ref<State>('all');

const states: State[] = ['all', 'solid', 'liquid', 'gas', 'special'];

const stateColors: Record<ElementState, { badge: string }> = {
    solid:   { badge: 'bg-amber-200 text-amber-800' },
    liquid:  { badge: 'bg-blue-200 text-blue-800' },
    gas:     { badge: 'bg-green-200 text-green-800' },
    special: { badge: 'bg-purple-200 text-purple-800' },
};

const avatarBg: Record<ElementState, string> = {
    solid:   'bg-amber-400',
    liquid:  'bg-blue-400',
    gas:     'bg-emerald-400',
    special: 'bg-purple-400',
};

function toState(state: string): ElementState {
    return state in stateColors ? (state as ElementState) : 'special';
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

const filtered = computed<ElementData[]>(() => {
    const q = search.value.toLowerCase();
    return props.elements.filter((el) => {
        const matchState = activeState.value === 'all' || el.state === activeState.value;
        const matchSearch =
            !q ||
            el.element_id.toLowerCase().includes(q) ||
            Object.values(el.name).some((n) => n.toLowerCase().includes(q));
        return matchState && matchSearch;
    });
});
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
        <header class="border-b border-gray-200 bg-white px-6 py-4 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Éléments</h1>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ filtered.length }} éléments</p>
        </header>

        <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row">
            <input
                v-model="search"
                type="search"
                placeholder="Rechercher un élément…"
                class="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
            />
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="state in states"
                    :key="state"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium capitalize transition-colors"
                    :class="activeState === state
                        ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'
                        : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                    @click="activeState = state"
                >
                    {{ state === 'all' ? 'Tous' : state }}
                </button>
            </div>
        </div>

        <main class="px-6 pb-12">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                <div
                    v-for="el in filtered"
                    :key="el.element_id"
                    class="flex cursor-default flex-col items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-800"
                >
                    <div
                        class="flex h-14 w-14 select-none items-center justify-center rounded-xl text-lg font-bold text-white"
                        :class="avatarBg[toState(el.state)]"
                    >
                        {{ initials(displayName(el)) }}
                    </div>
                    <div class="text-center">
                        <p class="text-sm font-medium leading-tight text-gray-900 dark:text-white">{{ displayName(el) }}</p>
                        <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ el.material_category }}</p>
                    </div>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                        :class="stateColors[toState(el.state)].badge"
                    >
                        {{ el.state }}
                    </span>
                </div>
            </div>

            <p v-if="filtered.length === 0" class="mt-16 text-center text-sm text-gray-400">
                Aucun élément trouvé.
            </p>
        </main>
    </div>
</template>
