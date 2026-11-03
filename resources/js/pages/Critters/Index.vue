<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CritterController from '@/actions/App/Http/Controllers/CritterController';
import RanchController from '@/actions/App/Http/Controllers/RanchController';
import AppLayout from '@/layouts/AppLayout.vue';
import type { CritterFamilyListItemData } from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

const props = defineProps<{ families: CritterFamilyListItemData[] }>();

// region Filters
const search = ref('');

type TempBucket = 'cold' | 'temperate' | 'hot';

const TEMP_BUCKETS: { key: TempBucket; label: string }[] = [
    { key: 'cold', label: 'Froid' },
    { key: 'temperate', label: 'Tempéré' },
    { key: 'hot', label: 'Chaud' },
];

const activeTemp = ref<TempBucket | null>(null);
const activeDiet = ref<string | null>(null);
const activeDlc = ref<string | null>(null);

/** dlc_id => short chip label (STRINGS.UI.<id>.NAME, leading "The" dropped). */
const DLC_LABELS: Record<string, string> = {
    EXPANSION1_ID: 'Spaced Out!',
    DLC2_ID: 'Frosty Planet Pack',
    DLC3_ID: 'Bionic Booster Pack',
    DLC4_ID: 'Prehistoric Planet Pack',
    DLC5_ID: 'Aquatic Planet Pack',
};

function toCelsius(kelvin: number): number {
    return kelvin - 273.15;
}

function matchesTempBucket(
    family: CritterFamilyListItemData,
    bucket: TempBucket,
): boolean {
    const minC = toCelsius(family.min_temp);
    const maxC = toCelsius(family.max_temp);
    if (bucket === 'cold') return minC < 15;
    if (bucket === 'hot') return maxC > 35;
    return maxC >= 15 && minC <= 35;
}

const dietOptions = computed<string[]>(() => {
    const set = new Set<string>();
    for (const f of props.families) {
        if (f.eats_summary) set.add(f.eats_summary);
    }
    return [...set].sort((a, b) => a.localeCompare(b));
});

const dlcOptions = computed<{ id: string; label: string }[]>(() => {
    const present = new Set<string>();
    for (const f of props.families) {
        if (f.dlc_id) present.add(f.dlc_id);
    }
    const known = Object.keys(DLC_LABELS).filter((id) => present.has(id));
    const unknown = [...present]
        .filter((id) => !(id in DLC_LABELS))
        .sort((a, b) => a.localeCompare(b));
    return [...known, ...unknown].map((id) => ({
        id,
        label: DLC_LABELS[id] ?? id,
    }));
});

const filtered = computed<CritterFamilyListItemData[]>(() => {
    const q = search.value.toLowerCase();
    return props.families.filter((f) => {
        const matchSearch =
            !q || f.name.toLowerCase().includes(q) || f.slug.includes(q);
        const matchTemp =
            activeTemp.value === null || matchesTempBucket(f, activeTemp.value);
        const matchDiet =
            activeDiet.value === null || f.eats_summary === activeDiet.value;
        const matchDlc =
            activeDlc.value === null || f.dlc_id === activeDlc.value;
        return matchSearch && matchTemp && matchDiet && matchDlc;
    });
});
// endregion

// region Tile animation
// Cards play the animated side-view idle loop; the front-facing portrait is a
// single static frame in the game, so it is only a fallback.
function tileImage(family: CritterFamilyListItemData): string | null {
    return family.image_idle ?? family.image;
}
// endregion

// region Card subtitle & variant navigation
function subtitle(family: CritterFamilyListItemData): string {
    const parts: string[] = [];
    if (family.variant_count > 1) {
        parts.push(`${family.variant_count} variantes`);
    }
    if (family.eats_summary) {
        parts.push(`mange ${family.eats_summary}`);
    }
    return parts.join(' · ');
}

function openVariant(slug: string, critterId: string): void {
    router.visit(
        CritterController.show(slug, { query: { variant: critterId } }),
    );
}
// endregion

// region Tinting
function familyHue(slug: string): number {
    let hash = 0;
    for (let i = 0; i < slug.length; i++) {
        hash = (hash * 31 + slug.charCodeAt(i)) >>> 0;
    }
    return hash % 360;
}
// endregion
</script>

<template>
    <div class="min-h-full pb-16 md:pb-0">
        <!-- region Sticky toolbar -->
        <header
            class="sticky top-0 z-10 border-b border-[oklch(82%_0.025_75)] bg-[oklch(94%_0.015_75)]/90 backdrop-blur-sm dark:border-[oklch(32%_0.020_250)] dark:bg-[oklch(22%_0.016_250)]/95"
        >
            <div class="mx-auto max-w-5xl px-5 py-4">
                <div class="flex items-baseline justify-between gap-3">
                    <h1 class="text-base font-semibold tracking-tight">
                        Créatures
                    </h1>
                    <div class="flex items-center gap-3">
                        <span
                            class="text-xs text-[oklch(42%_0.015_250)] tabular-nums dark:text-[oklch(66%_0.012_260)]"
                        >
                            {{ filtered.length
                            }}<span v-if="filtered.length !== families.length">
                                / {{ families.length }}</span
                            >
                        </span>
                        <Link
                            :href="RanchController.index()"
                            class="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-all hover:-translate-y-px hover:bg-emerald-500 hover:shadow-md focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:outline-none active:scale-[0.97]"
                        >
                            <svg
                                width="12"
                                height="12"
                                viewBox="0 0 18 18"
                                fill="none"
                            >
                                <rect
                                    x="2"
                                    y="5"
                                    width="14"
                                    height="9"
                                    rx="1"
                                    stroke="currentColor"
                                    stroke-width="1.4"
                                />
                                <path
                                    d="M2 8h14M2 11h14"
                                    stroke="currentColor"
                                    stroke-width="1.2"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M6 5v9M12 5v9"
                                    stroke="currentColor"
                                    stroke-width="1.2"
                                    stroke-linecap="round"
                                />
                            </svg>
                            Planificateur d'élevage
                        </Link>
                    </div>
                </div>

                <!-- Search -->
                <input
                    v-model="search"
                    type="search"
                    placeholder="Rechercher une créature…"
                    class="mt-2.5 w-full rounded-lg border border-[oklch(88%_0.01_250)] bg-[oklch(99%_0.003_260)] px-3 py-2 text-sm placeholder:text-[oklch(58%_0.01_250)] focus:border-oni-amber focus:ring-1 focus:ring-oni-amber focus:outline-none dark:border-[oklch(35%_0.015_260)] dark:bg-[oklch(18%_0.015_260)] dark:placeholder:text-[oklch(52%_0.012_260)]"
                />

                <!-- Filter chips -->
                <div class="mt-2.5 flex flex-wrap gap-1.5">
                    <button
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                        :class="
                            activeTemp === null
                                ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                        "
                        @click="activeTemp = null"
                    >
                        Toutes températures
                    </button>
                    <button
                        v-for="bucket in TEMP_BUCKETS"
                        :key="bucket.key"
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                        :class="
                            activeTemp === bucket.key
                                ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                        "
                        @click="
                            activeTemp =
                                activeTemp === bucket.key ? null : bucket.key
                        "
                    >
                        {{ bucket.label }}
                    </button>

                    <span
                        class="mx-1 self-center text-[oklch(70%_0.01_250)] dark:text-[oklch(45%_0.012_260)]"
                        >·</span
                    >

                    <button
                        v-for="diet in dietOptions"
                        :key="diet"
                        class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                        :class="
                            activeDiet === diet
                                ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                        "
                        @click="activeDiet = activeDiet === diet ? null : diet"
                    >
                        Mange {{ diet }}
                    </button>

                    <template v-if="dlcOptions.length > 0">
                        <span
                            class="mx-1 self-center text-[oklch(70%_0.01_250)] dark:text-[oklch(45%_0.012_260)]"
                            >·</span
                        >
                        <button
                            v-for="dlc in dlcOptions"
                            :key="dlc.id"
                            class="rounded px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none"
                            :class="
                                activeDlc === dlc.id
                                    ? 'bg-[oklch(16%_0.012_250)] text-[oklch(97%_0.005_260)] dark:bg-[oklch(88%_0.008_260)] dark:text-[oklch(14%_0.01_260)]'
                                    : 'text-[oklch(42%_0.015_250)] hover:bg-[oklch(90%_0.01_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)]'
                            "
                            @click="
                                activeDlc = activeDlc === dlc.id ? null : dlc.id
                            "
                        >
                            {{ dlc.label }}
                        </button>
                    </template>
                </div>
            </div>
        </header>
        <!-- endregion -->

        <!-- region Grid -->
        <main class="mx-auto max-w-5xl px-5 py-6">
            <p
                v-if="filtered.length === 0"
                class="mt-24 text-center text-sm text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
            >
                Aucune créature trouvée.
            </p>

            <div
                v-else
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
            >
                <Link
                    v-for="(family, i) in filtered"
                    :key="family.slug"
                    :href="CritterController.show(family.slug)"
                    class="critter-tile group relative flex flex-col overflow-hidden rounded-xl border border-black/5 text-left ring-1 ring-white/30 transition-[transform,box-shadow] duration-200 ease-[cubic-bezier(0.22,1,0.36,1)] ring-inset hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none dark:border-white/5 dark:ring-white/5"
                    :style="{
                        '--tile-hue': String(familyHue(family.slug)),
                        animationDelay: `${i * 22}ms`,
                    }"
                >
                    <div class="relative flex h-28 items-center justify-center">
                        <img
                            v-if="tileImage(family)"
                            :src="tileImage(family) ?? undefined"
                            :alt="family.name"
                            loading="lazy"
                            decoding="async"
                            class="h-24 w-24 object-contain drop-shadow-sm transition-transform duration-300 group-hover:scale-110"
                        />
                        <svg
                            v-else
                            viewBox="0 0 96 96"
                            fill="none"
                            class="h-16 w-16 opacity-40 transition-transform duration-300 group-hover:scale-110"
                            style="color: oklch(45% 0.08 var(--tile-hue))"
                        >
                            <ellipse
                                cx="48"
                                cy="58"
                                rx="28"
                                ry="20"
                                stroke="currentColor"
                                stroke-width="3"
                            />
                            <circle
                                cx="39"
                                cy="55"
                                r="3.2"
                                fill="currentColor"
                            />
                            <circle
                                cx="57"
                                cy="55"
                                r="3.2"
                                fill="currentColor"
                            />
                            <path
                                d="M21 60c-8 2.5-13 8-10 13"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                            />
                            <path
                                d="M75 60c8 2.5 13 8 10 13"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                            />
                            <path
                                d="M33 40c-3-8 1-16 8-19"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                            />
                            <path
                                d="M63 40c3-8-1-16-8-19"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                            />
                        </svg>

                        <!-- Other-variant preview stack -->
                        <div
                            v-if="family.variant_previews.length > 0"
                            class="absolute right-1.5 bottom-1.5 flex -space-x-2.5"
                        >
                            <button
                                v-for="(vp, vi) in family.variant_previews"
                                :key="vp.critter_id"
                                type="button"
                                :title="vp.name"
                                class="h-10 w-10 rounded-full border-2 border-[oklch(98%_0.01_260)] bg-[oklch(98%_0.01_260)] p-0.5 shadow-sm transition-transform duration-150 hover:z-20 hover:scale-110 focus-visible:z-20 focus-visible:ring-2 focus-visible:ring-oni-amber focus-visible:outline-none dark:border-[oklch(24%_0.02_260)] dark:bg-[oklch(24%_0.02_260)]"
                                :style="{ zIndex: 10 - vi }"
                                @click.stop.prevent="
                                    openVariant(family.slug, vp.critter_id)
                                "
                            >
                                <img
                                    :src="vp.image"
                                    :alt="vp.name"
                                    class="h-full w-full object-contain"
                                />
                            </button>
                        </div>

                        <span
                            v-if="family.dlc_id"
                            class="absolute top-1.5 left-1.5 rounded bg-[oklch(80%_0.14_80)] px-1 py-0.5 text-[8px] font-bold text-[oklch(28%_0.10_60)] dark:bg-[oklch(40%_0.12_80)] dark:text-[oklch(90%_0.08_80)]"
                            >DLC</span
                        >
                    </div>
                    <div
                        class="border-t border-[oklch(0%_0_0/0.06)] px-3 py-2.5 dark:border-[oklch(100%_0_0/0.06)]"
                    >
                        <div
                            class="truncate font-semibold text-[oklch(22%_0.025_60)] dark:text-[oklch(92%_0.012_65)]"
                        >
                            {{ family.name }}
                        </div>
                        <div
                            v-if="subtitle(family)"
                            class="mt-0.5 truncate text-xs text-[oklch(50%_0.015_250)] dark:text-[oklch(60%_0.012_260)]"
                        >
                            {{ subtitle(family) }}
                        </div>
                    </div>
                </Link>
            </div>
        </main>
        <!-- endregion -->
    </div>
</template>

<style scoped>
.critter-tile {
    background-image: linear-gradient(
        160deg,
        oklch(97% 0.02 var(--tile-hue)),
        oklch(93% 0.045 var(--tile-hue))
    );
    animation: critter-tile-in 280ms cubic-bezier(0.22, 1, 0.36, 1) both;
}

@media (prefers-color-scheme: dark) {
    .critter-tile {
        background-image: linear-gradient(
            160deg,
            oklch(27% 0.03 var(--tile-hue)),
            oklch(22% 0.028 var(--tile-hue))
        );
    }
}

@keyframes critter-tile-in {
    from {
        opacity: 0;
        transform: translateY(8px) scale(0.98);
    }
}

@media (prefers-reduced-motion: reduce) {
    .critter-tile {
        animation: none;
    }
}
</style>
