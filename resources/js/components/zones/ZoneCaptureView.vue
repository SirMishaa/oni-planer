<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { fitCellPx } from '@/components/zones/zoneMapGeometry';
import type { ZoneBoxData, ZoneCaptureData } from '@/types/App/Data/data.d';

// region Props

const props = defineProps<{
    capture: ZoneCaptureData;
    /** The map's box: the capture frames the same cells. Null before the layout. */
    box: ZoneBoxData | null;
    /** Cell size to draw at (the map's, zoom included); null fits the box like the map does at 100 %. */
    cellPx: number | null;
    alt: string;
}>();

// endregion

// region Size

/** Measured like the map's scroller, so a fitted capture matches the map's fitted scale. */
const scroll = ref<HTMLElement | null>(null);
const availableWidth = ref(0);
const viewportHeight = ref(window.innerHeight);
let resizeObserver: ResizeObserver | undefined;

const size = computed(() => {
    if (props.box === null) {
        return { width: props.capture.width, height: props.capture.height };
    }

    const cell =
        props.cellPx ??
        (availableWidth.value === 0
            ? null
            : fitCellPx(availableWidth.value, viewportHeight.value, props.box));

    return cell === null
        ? { width: props.capture.width, height: props.capture.height }
        : { width: props.box.width * cell, height: props.box.height * cell };
});

function onWindowResize(): void {
    viewportHeight.value = window.innerHeight;
}

onMounted(() => {
    if (scroll.value) {
        availableWidth.value = Math.round(scroll.value.clientWidth);
        resizeObserver = new ResizeObserver(([entry]) => {
            const width = Math.round(
                (entry?.target as HTMLElement | undefined)?.clientWidth ??
                    availableWidth.value,
            );

            if (width !== availableWidth.value) {
                requestAnimationFrame(() => {
                    availableWidth.value = width;
                });
            }
        });
        resizeObserver.observe(scroll.value);
    }

    window.addEventListener('resize', onWindowResize);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    window.removeEventListener('resize', onWindowResize);
});

// endregion
</script>

<template>
    <div ref="scroll" class="zone-capture-view">
        <img
            :src="capture.url"
            :width="size.width"
            :height="size.height"
            :style="{ width: `${size.width}px`, height: `${size.height}px` }"
            :alt="alt"
            class="zone-capture-view__image"
        />
    </div>
</template>

<style scoped>
/* The map's scroller: same padding, same height cap, the stage's top margin. */
.zone-capture-view {
    min-width: 0;
    overflow: auto;
    max-height: 72vh;
    padding: 12px;
}

.zone-capture-view__image {
    display: block;
    max-width: none;
    margin-inline: auto;
    margin-top: 18px;
    border-radius: 2px;
}
</style>
