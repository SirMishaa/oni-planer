<script setup lang="ts">
import { ref, watch } from 'vue';

// region Props

const props = withDefaults(
    defineProps<{
        src: string | null;
        /** Placeholder glyph when there is no image (or it fails to load). */
        kind: 'food' | 'plant' | 'critter' | 'building';
        size: number;
        alt?: string;
        dimmed?: boolean;
    }>(),
    { alt: '', dimmed: false },
);

// endregion

// region Fallback

const failed = ref(false);

watch(
    () => props.src,
    () => {
        failed.value = false;
    },
);

// endregion
</script>

<template>
    <span
        class="game-sprite"
        :class="{ 'game-sprite--dimmed': dimmed }"
        :style="{ width: `${size}px`, height: `${size}px` }"
    >
        <!-- region Image -->
        <img
            v-if="src && !failed"
            :src="src"
            :alt="alt"
            :title="alt || undefined"
            loading="lazy"
            decoding="async"
            class="size-full object-contain"
            @error="failed = true"
        />
        <!-- endregion -->

        <!-- region Placeholder -->
        <span
            v-else
            class="game-sprite__placeholder"
            :role="alt ? 'img' : undefined"
            :aria-label="alt || undefined"
            :aria-hidden="alt ? undefined : 'true'"
            :title="alt || undefined"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <template v-if="kind === 'food'">
                    <path d="M4 12h16a8 7 0 0 1-16 0Z" />
                    <path
                        d="M9 4.5c-.8 1 .8 2-.1 3.2M13 4.5c-.8 1 .8 2-.1 3.2"
                    />
                </template>
                <template v-else-if="kind === 'plant'">
                    <path d="M12 21v-9" />
                    <path d="M12 12c0-4 2.6-6.5 7-6.5 0 4.2-2.7 6.5-7 6.5Z" />
                    <path
                        d="M12 14.5c0-3.2-2.2-5.2-6-5.2 0 3.3 2.3 5.2 6 5.2Z"
                    />
                </template>
                <template v-else-if="kind === 'critter'">
                    <ellipse cx="12" cy="13.5" rx="7.5" ry="5.5" />
                    <path d="M9.5 12.5h.01M14.5 12.5h.01" stroke-width="2.4" />
                </template>
                <template v-else>
                    <path d="M4 20V9l8-5 8 5v11Z" />
                    <path d="M10 20v-5h4v5" />
                </template>
            </svg>
        </span>
        <!-- endregion -->
    </span>
</template>

<style scoped>
.game-sprite {
    display: inline-grid;
    flex: none;
    place-items: center;
}

.game-sprite--dimmed {
    opacity: 0.28;
    filter: grayscale(1);
}

.game-sprite__placeholder {
    display: grid;
    place-items: center;
    width: 100%;
    height: 100%;
    border-radius: 22%;
    background: var(--ink-2);
    color: var(--text-3);
}

.game-sprite__placeholder svg {
    width: 62%;
    height: 62%;
}
</style>
