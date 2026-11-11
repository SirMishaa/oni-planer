<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    ColonyStatusData,
    UplinkMessageData,
} from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

defineProps<{
    status: ColonyStatusData;
    messages: UplinkMessageData[];
    selected: { id: number; payload: string } | null;
}>();
</script>

<template>
    <!-- region Page (built in Task 9) -->
    <div />
    <!-- endregion -->
</template>
