<script setup lang="ts">
// region Props

defineProps<{
    name:
        | 'map'
        | 'list'
        | 'chart'
        | 'leaf'
        | 'layers'
        | 'temperature'
        | 'alert'
        | 'clock'
        | 'cube'
        | 'check'
        | 'question'
        | 'grid'
        | 'pin';
    size?: number;
}>();

// endregion
</script>

<template>
    <svg
        class="zone-icon"
        viewBox="0 0 24 24"
        :width="size ?? 15"
        :height="size ?? 15"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <template v-if="name === 'map'">
            <path d="M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3z" />
            <path d="M9 3v15M15 6v15" />
        </template>
        <path
            v-else-if="name === 'list'"
            d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"
        />
        <template v-else-if="name === 'chart'">
            <path d="M3 3v18h18" />
            <path d="M7 15l4-5 4 3 5-7" />
        </template>
        <template v-else-if="name === 'leaf'">
            <path d="M5 19c0-8 5-14 15-15-1 10-7 15-15 15z" />
            <path d="M5 19l8-8" />
        </template>
        <template v-else-if="name === 'layers'">
            <path d="M12 3l9 5-9 5-9-5z" />
            <path d="M3 13l9 5 9-5" />
        </template>
        <path
            v-else-if="name === 'temperature'"
            d="M14 14.8V4a2 2 0 10-4 0v10.8a4 4 0 104 0z"
        />
        <template v-else-if="name === 'alert'">
            <path d="M12 3l10 18H2z" />
            <path d="M12 10v4M12 17.5h.01" />
        </template>
        <template v-else-if="name === 'clock'">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </template>
        <template v-else-if="name === 'cube'">
            <path d="M12 2l9 5v10l-9 5-9-5V7z" />
            <path d="M3 7l9 5 9-5M12 12v10" />
        </template>
        <path v-else-if="name === 'check'" d="M5 12l5 5 9-10" />
        <template v-else-if="name === 'question'">
            <circle cx="12" cy="12" r="9" />
            <path
                d="M9.5 9.5a2.5 2.5 0 114 2c-.9.6-1.5 1.2-1.5 2.5M12 17h.01"
            />
        </template>
        <template v-else-if="name === 'grid'">
            <path d="M4 4h16v16H4zM4 12h16M12 4v16" />
        </template>
        <template v-else>
            <path d="M12 21s-6-5.5-6-11a6 6 0 1112 0c0 5.5-6 11-6 11z" />
            <circle cx="12" cy="10" r="2" />
        </template>
    </svg>
</template>

<style scoped>
.zone-icon {
    flex: none;
}
</style>
