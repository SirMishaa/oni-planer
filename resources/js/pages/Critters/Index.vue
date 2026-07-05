<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { CritterFamilyListItemData } from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

defineProps<{ families: CritterFamilyListItemData[] }>();
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-6 pb-24 md:pb-6">
        <!-- region Header -->
        <div class="mb-5 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-[oklch(18%_0.025_60)] dark:text-[oklch(92%_0.012_65)]">
                    Créatures
                </h1>
                <p class="mt-0.5 text-xs text-[oklch(52%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">
                    {{ families.length }} famille{{ families.length > 1 ? 's' : '' }}
                </p>
            </div>
            <Link
                href="/ranch"
                class="flex items-center gap-2 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition-all hover:bg-emerald-500 hover:-translate-y-px hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 active:scale-[0.97]"
            >
                <svg width="14" height="14" viewBox="0 0 18 18" fill="none">
                    <rect x="2" y="5" width="14" height="9" rx="1" stroke="currentColor" stroke-width="1.4" />
                    <path d="M2 8h14M2 11h14" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                    <path d="M6 5v9M12 5v9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                </svg>
                Planificateur d'élevage
            </Link>
        </div>
        <!-- endregion -->

        <!-- region Grid -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <Link
                v-for="(family, i) in families"
                :key="family.slug"
                :href="`/critters/${family.slug}`"
                class="codex-thumb group flex flex-col overflow-hidden rounded-xl border border-black/5 text-left ring-1 ring-white/30 transition-[transform,box-shadow] duration-200 ease-[cubic-bezier(0.22,1,0.36,1)] ring-inset hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-oni-amber dark:border-white/5 dark:ring-white/5"
                :style="{ '--tile-hue': '290', animationDelay: `${i * 22}ms` }"
            >
                <div class="flex h-28 items-center justify-center">
                    <img
                        v-if="family.image"
                        :src="family.image"
                        :alt="family.name"
                        class="h-24 w-24 object-contain drop-shadow-sm transition-transform duration-300 group-hover:scale-110"
                    />
                    <span v-else class="text-4xl opacity-20 transition-transform duration-300 group-hover:scale-110">🐾</span>
                </div>
                <div class="border-t border-[oklch(0%_0_0/0.06)] px-3 py-2.5 dark:border-[oklch(100%_0_0/0.06)]">
                    <div class="truncate font-semibold text-[oklch(22%_0.025_60)] dark:text-[oklch(92%_0.012_65)]">
                        {{ family.name }}
                    </div>
                    <div class="mt-0.5 truncate text-xs text-[oklch(50%_0.015_250)] dark:text-[oklch(60%_0.012_260)]">
                        {{ family.variant_count }} variant{{ family.variant_count > 1 ? 's' : '' }}
                        <template v-if="family.eats_summary"> · mange {{ family.eats_summary }}</template>
                    </div>
                </div>
            </Link>
        </div>
        <!-- endregion -->
    </div>
</template>

<style scoped>
/* Per-entry thumbnail card — identical treatment to CodexPanel codex-thumb */
.codex-thumb {
    background-image: linear-gradient(
        160deg,
        oklch(97% 0.02 var(--tile-hue)),
        oklch(93% 0.045 var(--tile-hue))
    );
    animation: codex-tile-in 280ms cubic-bezier(0.22, 1, 0.36, 1) both;
}

@media (prefers-color-scheme: dark) {
    .codex-thumb {
        background-image: linear-gradient(
            160deg,
            oklch(27% 0.03 var(--tile-hue)),
            oklch(22% 0.028 var(--tile-hue))
        );
    }
}

@keyframes codex-tile-in {
    from {
        opacity: 0;
        transform: translateY(8px) scale(0.98);
    }
}

@media (prefers-reduced-motion: reduce) {
    .codex-thumb {
        animation: none;
    }
}
</style>
