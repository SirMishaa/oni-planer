<script setup lang="ts">
type Variant = 'solid' | 'liquid' | 'gas' | 'special' | 'neutral';

withDefaults(defineProps<{ variant?: Variant }>(), { variant: 'neutral' });

const classes: Record<Variant, string> = {
    solid: 'bg-[oklch(90%_0.045_52)] text-[oklch(34%_0.12_52)] dark:bg-[oklch(24%_0.065_52)] dark:text-[oklch(76%_0.13_52)]',
    liquid: 'bg-[oklch(88%_0.04_222)] text-[oklch(28%_0.12_222)] dark:bg-[oklch(19%_0.07_222)] dark:text-[oklch(70%_0.15_222)]',
    gas: 'bg-[oklch(88%_0.045_142)] text-[oklch(26%_0.1_142)] dark:bg-[oklch(19%_0.07_142)] dark:text-[oklch(72%_0.14_142)]',
    special: 'bg-[oklch(88%_0.04_290)] text-[oklch(28%_0.13_290)] dark:bg-[oklch(19%_0.08_290)] dark:text-[oklch(70%_0.17_290)]',
    neutral: 'bg-[oklch(88%_0.008_250)] text-[oklch(38%_0.015_250)] dark:bg-[oklch(24%_0.013_260)] dark:text-[oklch(60%_0.012_260)]',
};
</script>

<template>
    <span
        class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium capitalize"
        :class="classes[variant]"
    >
        <slot />
    </span>
</template>
