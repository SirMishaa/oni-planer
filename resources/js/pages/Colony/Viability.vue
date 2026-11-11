<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { ColonyStatusData } from '@/types/App/Data/data.d';
import type { ViabilityData } from '@/types/App/Data/Viability/data.d';

defineOptions({ layout: AppLayout });

defineProps<{
    status: ColonyStatusData;
    viability: ViabilityData | null;
}>();
</script>

<template>
    <!-- region Page (built in Task 8) -->
    <div />
    <!-- endregion -->
</template>
