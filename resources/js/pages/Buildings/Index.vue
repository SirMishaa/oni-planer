<script setup lang="ts">
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BuildingData } from '@/types/App/Data/data';

defineOptions({ layout: AppLayout });

const props = defineProps<{ buildings: BuildingData[] }>();

// region Constants
const CATEGORY_ORDER: [string, string][] = [
    ['Base', 'Construction'],
    ['Oxygen', 'Oxygène'],
    ['Power', 'Énergie'],
    ['Food', 'Nourriture'],
    ['Plumbing', 'Plomberie'],
    ['HVAC', 'Ventilation'],
    ['Refining', 'Raffinage'],
    ['Medical', 'Médecine'],
    ['Furniture', 'Mobilier'],
    ['Equipment', 'Équipement'],
    ['Utilities', 'Utilitaires'],
    ['Automation', 'Automatisation'],
    ['Conveyance', 'Transport'],
    ['Rocketry', 'Fusées'],
    ['HEP', 'Radiation'],
    ['Other', 'Autre'],
];

const CATEGORY_LABEL: Record<string, string> = Object.fromEntries(CATEGORY_ORDER);

const CATEGORY_SVG: Record<string, string> = {
    Base: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="6" y="38" width="30" height="50" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
        <rect x="40" y="18" width="50" height="70" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
        <rect x="14" y="52" width="10" height="12" fill="currentColor"/>
        <rect x="50" y="30" width="12" height="14" fill="currentColor"/>
        <rect x="68" y="30" width="12" height="14" fill="currentColor"/>
        <rect x="50" y="52" width="12" height="14" fill="currentColor"/>
        <rect x="68" y="52" width="12" height="14" fill="currentColor"/>
        <path d="M40 18L56 6l16 12" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
    </svg>`,
    Oxygen: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="30" cy="62" r="22" stroke="currentColor" stroke-width="3"/>
        <circle cx="62" cy="38" r="15" stroke="currentColor" stroke-width="3"/>
        <circle cx="74" cy="72" r="9" stroke="currentColor" stroke-width="2.5"/>
        <circle cx="18" cy="26" r="6" stroke="currentColor" stroke-width="2"/>
        <circle cx="50" cy="14" r="4" stroke="currentColor" stroke-width="2"/>
    </svg>`,
    Power: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M56 6L18 52h26L28 90l50-48H52L56 6z" fill="currentColor"/>
    </svg>`,
    Food: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M48 82V14" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <ellipse cx="34" cy="30" rx="11" ry="16" transform="rotate(-22 34 30)" stroke="currentColor" stroke-width="2.5"/>
        <ellipse cx="62" cy="30" rx="11" ry="16" transform="rotate(22 62 30)" stroke="currentColor" stroke-width="2.5"/>
        <ellipse cx="28" cy="54" rx="11" ry="16" transform="rotate(-28 28 54)" stroke="currentColor" stroke-width="2.5"/>
        <ellipse cx="68" cy="54" rx="11" ry="16" transform="rotate(28 68 54)" stroke="currentColor" stroke-width="2.5"/>
    </svg>`,
    Plumbing: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M14 50h22" stroke="currentColor" stroke-width="7" stroke-linecap="round"/>
        <path d="M36 50v-24h44" stroke="currentColor" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 68h14v14" stroke="currentColor" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="80" cy="26" r="7" stroke="currentColor" stroke-width="3"/>
        <circle cx="14" cy="68" r="7" stroke="currentColor" stroke-width="3"/>
    </svg>`,
    HVAC: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="48" cy="48" r="10" fill="currentColor"/>
        <path d="M48 38 C50 30 62 12 76 10 C72 26 58 36 48 38Z" fill="currentColor"/>
        <path d="M58 48 C66 46 84 50 88 64 C72 66 60 56 58 48Z" fill="currentColor"/>
        <path d="M48 58 C46 66 34 84 20 86 C24 70 38 60 48 58Z" fill="currentColor"/>
        <path d="M38 48 C30 50 12 46 8 32 C24 30 36 40 38 48Z" fill="currentColor"/>
        <circle cx="48" cy="48" r="22" stroke="currentColor" stroke-width="2" stroke-dasharray="5 4"/>
    </svg>`,
    Refining: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M36 10h24M36 10v28L12 72a10 10 0 009 14h54a10 10 0 009-14L60 38V10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="34" cy="70" r="5" fill="currentColor"/>
        <circle cx="52" cy="78" r="4" fill="currentColor"/>
        <circle cx="64" cy="66" r="6" fill="currentColor"/>
    </svg>`,
    Medical: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M36 10h24v26h26v20H60v26H36V56H10V36h26V10z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
        <circle cx="48" cy="48" r="8" stroke="currentColor" stroke-width="2"/>
    </svg>`,
    Furniture: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M18 26h60" stroke="currentColor" stroke-width="5" stroke-linecap="round"/>
        <path d="M26 26v50M70 26v50" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
        <path d="M18 58h60" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
        <path d="M10 58h76" stroke="currentColor" stroke-width="6" stroke-linecap="round"/>
    </svg>`,
    Equipment: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="48" cy="48" r="12" stroke="currentColor" stroke-width="3"/>
        <path d="M46 8h4l2 9a28 28 0 016 2.5l7.6-4.4 2.8 2.8L64 25.5A28 28 0 0166.5 32l9 2v4l-9 2A28 28 0 0164 46.5l4.4 7.6-2.8 2.8L58 52.5A28 28 0 0152 55l-2 9h-4l-2-9a28 28 0 01-6-2.5l-7.6 4.4-2.8-2.8L31.5 46.5A28 28 0 0129.5 40l-9-2v-4l9-2A28 28 0 0132 25.5l-4.4-7.6 2.8-2.8 7.6 4.4A28 28 0 0144 15l2-9z" stroke="currentColor" stroke-width="3"/>
    </svg>`,
    Utilities: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M68 12a22 22 0 00-22 26L18 66a9 9 0 000 12 9 9 0 0012 0l28-28a22 22 0 0026-22 22 22 0 01-11 4l-9-9a22 22 0 014-11z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
        <circle cx="24" cy="72" r="4" fill="currentColor"/>
    </svg>`,
    Automation: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="30" y="30" width="36" height="36" rx="4" stroke="currentColor" stroke-width="3"/>
        <circle cx="48" cy="48" r="7" fill="currentColor"/>
        <path d="M48 14v16M48 66v16M14 48h16M66 48h16" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <path d="M24 24l10 10M62 62l10 10M62 24L52 34M24 72l10-10" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
    </svg>`,
    Conveyance: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M10 48h76" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
        <path d="M10 36v24" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
        <circle cx="10" cy="48" r="10" stroke="currentColor" stroke-width="3"/>
        <circle cx="86" cy="48" r="10" stroke="currentColor" stroke-width="3"/>
        <path d="M62 30l22 18-22 18" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>`,
    Rocketry: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M48 6C48 6 70 18 70 50v16l-22 8-22-8V50C8 18 48 6 48 6z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
        <circle cx="48" cy="38" r="9" stroke="currentColor" stroke-width="3"/>
        <path d="M26 62L14 80h16M70 62l12 18H66" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>`,
    HEP: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="48" cy="48" r="9" fill="currentColor"/>
        <circle cx="48" cy="48" r="30" stroke="currentColor" stroke-width="2" stroke-dasharray="6 5"/>
        <path d="M48 39V14M56 43l21-12M56 53l21 12M48 57v22M40 53L19 65M40 43L19 31" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/>
    </svg>`,
    Other: `<svg viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="24" cy="24" r="5" fill="currentColor"/>
        <circle cx="48" cy="24" r="5" fill="currentColor"/>
        <circle cx="72" cy="24" r="5" fill="currentColor"/>
        <circle cx="24" cy="48" r="5" fill="currentColor"/>
        <circle cx="48" cy="48" r="5" fill="currentColor"/>
        <circle cx="72" cy="48" r="5" fill="currentColor"/>
        <circle cx="24" cy="72" r="5" fill="currentColor"/>
        <circle cx="48" cy="72" r="5" fill="currentColor"/>
        <circle cx="72" cy="72" r="5" fill="currentColor"/>
    </svg>`,
};

// hue, chroma
const CATEGORY_HUES: Record<string, [number, number]> = {
    Base:       [75,  0.10],
    Oxygen:     [210, 0.14],
    Power:      [85,  0.18],
    Food:       [48,  0.16],
    Plumbing:   [220, 0.12],
    HVAC:       [190, 0.12],
    Refining:   [35,  0.14],
    Medical:    [155, 0.13],
    Furniture:  [60,  0.09],
    Equipment:  [270, 0.12],
    Utilities:  [240, 0.10],
    Automation: [305, 0.12],
    Conveyance: [25,  0.13],
    Rocketry:   [250, 0.14],
    HEP:        [130, 0.15],
    Other:      [260, 0.06],
};
// endregion

const search = ref('');
const activeCategory = ref<string>('all');

const categories = computed<string[]>(() => {
    const available = new Set(props.buildings.map((b) => b.category));
    return CATEGORY_ORDER.map(([key]) => key).filter((k) => available.has(k));
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
        const matchCat = activeCategory.value === 'all' || b.category === activeCategory.value;
        const matchSearch =
            !q ||
            b.building_id.toLowerCase().includes(q) ||
            Object.values(b.name).some((n) => n.toLowerCase().includes(q));
        return matchCat && matchSearch;
    });
});

type SubGroup = { label: string | null; items: BuildingData[] };
type CategoryGroup = { key: string; label: string; subGroups: SubGroup[] };

// Grouped view: only when showing all categories with no search query
const groupedBuildings = computed<CategoryGroup[] | null>(() => {
    if (activeCategory.value !== 'all' || search.value) return null;
    return CATEGORY_ORDER
        .map(([key, label]) => {
            const items = props.buildings.filter((b) => b.category === key);
            if (items.length === 0) return null;

            // Collect named sub-groups in insertion order, ungrouped last
            const named = new Map<string, BuildingData[]>();
            const ungrouped: BuildingData[] = [];
            for (const b of items) {
                if (b.sub_group) {
                    if (!named.has(b.sub_group)) named.set(b.sub_group, []);
                    named.get(b.sub_group)!.push(b);
                } else {
                    ungrouped.push(b);
                }
            }

            const subGroups: SubGroup[] = [
                ...[...named.entries()].map(([sg, sgItems]) => ({ label: sg, items: sgItems })),
                ...(ungrouped.length > 0 ? [{ label: null, items: ungrouped }] : []),
            ];

            return { key, label, subGroups };
        })
        .filter((g): g is CategoryGroup => g !== null);
});

function categoryCssVars(cat: string): Record<string, string> {
    const [h, c] = CATEGORY_HUES[cat] ?? [260, 0.06];
    return { '--cat-h': String(h), '--cat-c': String(c) };
}

function categoryAccentColor(cat: string): string {
    const [h, c] = CATEGORY_HUES[cat] ?? [260, 0.06];
    return `oklch(58% ${c} ${h})`;
}

function displayName(b: BuildingData): string {
    return b.name['en'] ?? b.building_id;
}

function powerLabel(b: BuildingData): string | null {
    if (b.power_generation && b.power_generation > 0) return `+${b.power_generation}W`;
    if (b.power_consumption && b.power_consumption > 0) return `-${b.power_consumption}W`;
    return null;
}

function powerIsGen(b: BuildingData): boolean {
    return !!(b.power_generation && b.power_generation > 0);
}
</script>

<template>
    <div class="min-h-full pb-16 md:pb-0">
        <!-- region Sticky toolbar -->
        <header
            class="sticky top-0 z-10 border-b border-[oklch(82%_0.025_75)] bg-[oklch(94%_0.015_75)]/90 backdrop-blur-sm dark:border-[oklch(32%_0.020_250)] dark:bg-[oklch(22%_0.016_250)]/95"
        >
            <div class="mx-auto max-w-5xl px-5 py-4">
                <div class="flex items-baseline justify-between">
                    <h1 class="text-base font-semibold tracking-tight">Bâtiments</h1>
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
                        {{ CATEGORY_LABEL[cat] ?? cat }}
                        <span class="ml-1 opacity-50">{{ categoryCounts[cat] }}</span>
                    </button>
                </div>
            </div>
        </header>
        <!-- endregion -->

        <!-- region Grouped view (all categories, no search) -->
        <main v-if="groupedBuildings" class="mx-auto max-w-5xl px-5 py-6 space-y-5">
            <section
                v-for="(group, gi) in groupedBuildings"
                :key="group.key"
                class="cat-section relative overflow-hidden rounded-xl px-4 pt-3 pb-4"
                :style="{ ...categoryCssVars(group.key), '--section-i': gi }"
            >
                <!-- Decorative category illustration -->
                <div
                    class="cat-deco"
                    :style="{ color: categoryAccentColor(group.key) }"
                    v-html="CATEGORY_SVG[group.key]"
                ></div>

                <div class="flex items-center gap-2.5 mb-3">
                    <span
                        class="h-3 w-3 rounded-full shrink-0 ring-2 ring-white/40 dark:ring-white/10"
                        :style="{ background: categoryAccentColor(group.key) }"
                    ></span>
                    <h2
                        class="text-sm font-bold tracking-wide"
                        :style="{ color: categoryAccentColor(group.key) }"
                    >
                        {{ group.label }}
                    </h2>
                    <span class="text-[11px] font-medium tabular-nums rounded-full px-1.5 py-0.5 bg-[oklch(0%_0_0/0.07)] text-[oklch(35%_0.010_260)] dark:bg-[oklch(100%_0_0/0.08)] dark:text-[oklch(65%_0.010_260)]">
                        {{ group.subGroups.reduce((n, sg) => n + sg.items.length, 0) }}
                    </span>
                </div>

                <div class="space-y-3">
                    <div v-for="sg in group.subGroups" :key="sg.label ?? '__other__'">
                        <!-- Sub-group label -->
                        <p
                            v-if="sg.label"
                            class="mb-1.5 text-[10px] font-semibold uppercase tracking-widest text-[oklch(45%_0.010_260)] dark:text-[oklch(58%_0.010_260)]"
                        >
                            {{ sg.label }}
                        </p>

                        <ul class="grid grid-cols-3 gap-2.5 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-7">
                            <li
                                v-for="(b, bi) in sg.items"
                                :key="b.building_id"
                                class="building-card relative flex flex-col items-center gap-1 rounded-lg px-1 pt-2 pb-1.5 cursor-default h-full"
                                :style="{ '--card-i': Math.min(bi, 12) }"
                            >
                                <!-- Icon -->
                                <div class="h-16 w-16 shrink-0">
                                    <img
                                        :src="`/assets/game/buildings/${b.building_id}.webp`"
                                        :alt="displayName(b)"
                                        class="h-full w-full object-contain"
                                        loading="lazy"
                                    />
                                </div>

                                <!-- Power badge -->
                                <span
                                    v-if="powerLabel(b)"
                                    class="rounded px-1 py-0.5 text-[9px] font-semibold leading-none tabular-nums"
                                    :class="
                                        powerIsGen(b)
                                            ? 'bg-[oklch(88%_0.12_148)] text-[oklch(30%_0.10_148)] dark:bg-[oklch(38%_0.12_148)] dark:text-[oklch(88%_0.08_148)]'
                                            : 'bg-[oklch(88%_0.10_60)] text-[oklch(32%_0.10_42)] dark:bg-[oklch(36%_0.10_42)] dark:text-[oklch(88%_0.08_60)]'
                                    "
                                >
                                    {{ powerLabel(b) }}
                                </span>
                                <span v-else class="h-[17px]"></span>

                                <!-- Name -->
                                <p class="mt-auto w-full text-center text-[11px] leading-snug rounded px-1 py-0.5 bg-[oklch(100%_0_0/0.55)] text-[oklch(22%_0.010_260)] dark:bg-[oklch(0%_0_0/0.40)] dark:text-[oklch(88%_0.008_260)]">
                                    {{ displayName(b) }}
                                </p>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>
        </main>
        <!-- endregion -->

        <!-- region Filtered list (search active or single category selected) -->
        <main v-else class="mx-auto max-w-3xl px-5 py-3">
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
                    <div class="-mx-3 flex items-center gap-3 rounded-lg px-3 py-2.5">
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

                        <!-- Meta: category badge + size + power -->
                        <div class="flex shrink-0 items-center gap-2.5">
                            <span
                                v-if="activeCategory === 'all'"
                                class="hidden rounded px-1.5 py-0.5 text-[10px] font-medium text-[oklch(40%_0.015_250)] ring-1 ring-[oklch(86%_0.01_250)] sm:block dark:text-[oklch(64%_0.012_260)] dark:ring-[oklch(36%_0.014_260)]"
                            >
                                {{ CATEGORY_LABEL[b.category] ?? b.category }}
                            </span>
                            <span
                                class="hidden text-xs tabular-nums text-[oklch(42%_0.015_250)] sm:block dark:text-[oklch(66%_0.012_260)]"
                            >
                                {{ b.width }}×{{ b.height }}
                            </span>
                            <span
                                v-if="powerLabel(b)"
                                class="text-xs font-medium tabular-nums"
                                :class="
                                    powerIsGen(b)
                                        ? 'text-[oklch(60%_0.14_148)]'
                                        : 'text-[oklch(58%_0.12_30)]'
                                "
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

<style scoped>
.cat-deco {
    position: absolute;
    bottom: -10px;
    right: -10px;
    width: 110px;
    height: 110px;
    opacity: 0.13;
    pointer-events: none;
    transform: rotate(-10deg);
}

:global(.dark) .cat-deco {
    opacity: 0.09;
}

@keyframes section-in {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes card-in {
    from { opacity: 0; transform: translateY(6px) scale(0.92); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.cat-section {
    background: linear-gradient(
        135deg,
        oklch(91% calc(var(--cat-c) * 0.40) var(--cat-h)) 0%,
        oklch(96% 0.004 260) 100%
    );
    animation: section-in 320ms cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: calc(var(--section-i, 0) * 45ms);
}

:global(.dark) .cat-section {
    background: linear-gradient(
        135deg,
        oklch(20% calc(var(--cat-c) * 0.70) var(--cat-h)) 0%,
        oklch(17% 0.008 260) 100%
    );
}

.building-card {
    transition:
        transform 200ms cubic-bezier(0.22, 1, 0.36, 1),
        background 160ms ease-out,
        box-shadow 200ms cubic-bezier(0.22, 1, 0.36, 1);
    animation: card-in 280ms cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: calc(var(--card-i, 0) * 25ms + 80ms);
}

.building-card img {
    transition: filter 200ms cubic-bezier(0.22, 1, 0.36, 1);
}

.building-card:hover {
    transform: scale(1.12) translateY(-2px);
    background: oklch(100% 0 0 / 0.60);
    box-shadow:
        0 4px 14px oklch(40% calc(var(--cat-c) * 0.9) var(--cat-h) / 0.22),
        0 0 0 1px oklch(68% calc(var(--cat-c) * 0.7) var(--cat-h) / 0.35);
}

.building-card:hover img {
    filter: drop-shadow(0 2px 7px oklch(55% calc(var(--cat-c) * 1.3) var(--cat-h) / 0.55));
}

:global(.dark) .building-card:hover {
    background: oklch(100% 0 0 / 0.08);
    box-shadow:
        0 4px 14px oklch(20% calc(var(--cat-c) * 1.2) var(--cat-h) / 0.50),
        0 0 0 1px oklch(55% calc(var(--cat-c) * 0.8) var(--cat-h) / 0.30);
}

:global(.dark) .building-card:hover img {
    filter: drop-shadow(0 2px 8px oklch(65% calc(var(--cat-c) * 1.5) var(--cat-h) / 0.65));
}
</style>
