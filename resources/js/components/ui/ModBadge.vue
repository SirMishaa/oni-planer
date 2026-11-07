<script setup lang="ts">
defineProps<{ mod: { workshop_id: string; title: string } }>();
</script>

<template>
    <span
        class="inline-flex max-w-full items-center gap-1 rounded bg-[oklch(88%_0.04_190)] px-1.5 py-0.5 text-[10px] font-medium text-[oklch(28%_0.09_190)] dark:bg-[oklch(21%_0.05_190)] dark:text-[oklch(72%_0.11_190)]"
        :title="mod.title"
    >
        <span class="font-bold tracking-wide uppercase">Mod</span>
        <span class="truncate">{{ mod.title }}</span>
    </span>
</template>
