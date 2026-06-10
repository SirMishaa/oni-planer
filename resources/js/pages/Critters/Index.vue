<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { CritterFamilyListItemData } from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

defineProps<{ families: CritterFamilyListItemData[] }>();
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-6">
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Créatures</h1>
            <Link href="/ranch" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500">
                Planificateur d'élevage
            </Link>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <Link
                v-for="family in families"
                :key="family.slug"
                :href="`/critters/${family.slug}`"
                class="codex-thumb group flex flex-col overflow-hidden rounded-xl border border-black/5 text-left ring-1 ring-white/30 transition-[transform,box-shadow] duration-200 ease-[cubic-bezier(0.22,1,0.36,1)] ring-inset hover:-translate-y-0.5 hover:shadow-md dark:border-white/5 dark:ring-white/5"
            >
                <div class="flex h-28 items-center justify-center bg-black/[0.03] dark:bg-white/[0.03]">
                    <img v-if="family.image" :src="family.image" :alt="family.name" class="h-24 w-24 object-contain transition-transform duration-300 group-hover:scale-105" />
                    <span v-else class="text-3xl opacity-30">🐾</span>
                </div>
                <div class="p-3">
                    <div class="font-medium">{{ family.name }}</div>
                    <div class="text-xs opacity-60">
                        {{ family.variant_count }} variant{{ family.variant_count > 1 ? 's' : '' }}
                        <template v-if="family.eats_summary"> · mange {{ family.eats_summary }}</template>
                    </div>
                </div>
            </Link>
        </div>
    </div>
</template>
