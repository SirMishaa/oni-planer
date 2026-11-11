<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ColonyController from '@/actions/App/Http/Controllers/ColonyController';
import { useT } from '@/composables/useT';
import type { ColonyStatusData } from '@/types/App/Data/data.d';

// region Props

const props = defineProps<{
    status: ColonyStatusData;
    live: boolean;
    ageLabel: string;
}>();

// endregion

// region Text

const { t } = useT();

/** `en direct · DeepWaterGo · cycle 59` or `vu il y a 2 h · cycle 59`. */
const parts = computed(() => {
    const result = [props.ageLabel];

    if (props.live && props.status.colonyName) {
        result.push(props.status.colonyName);
    }

    if (props.status.cycle !== null) {
        result.push(t('colony.status.cycle', { cycle: props.status.cycle }));
    }

    return result;
});

// endregion
</script>

<template>
    <Link
        :href="ColonyController.uplink.url()"
        class="uplink-status"
        :title="t('colony.status.inspector')"
    >
        <span
            class="uplink-status__dot"
            :class="{ 'uplink-status__dot--live': live }"
            aria-hidden="true"
        />
        <span>
            <template v-for="(part, index) in parts" :key="index">
                <span
                    v-if="index > 0"
                    class="uplink-status__sep"
                    aria-hidden="true"
                >
                    ·
                </span>
                <span :class="{ 'uplink-status__lead': index === 0 }">{{
                    part
                }}</span>
            </template>
        </span>
        <span class="sr-only">{{ t('colony.status.inspector') }}</span>
    </Link>
</template>

<style scoped>
.uplink-status {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    min-height: 36px;
    padding: 6px 12px;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text-2);
    font-size: 13.5px;
    text-decoration: none;
    transition:
        border-color 160ms ease-out,
        color 160ms ease-out;
}

.uplink-status:hover {
    border-color: var(--text-3);
    color: var(--text);
}

.uplink-status__lead {
    color: var(--text);
    font-weight: 600;
}

.uplink-status__sep {
    margin-inline: 0.35em;
    color: var(--text-3);
}

.uplink-status__dot {
    width: 8px;
    height: 8px;
    flex: none;
    border: 1.5px solid var(--text-3);
    border-radius: 50%;
}

.uplink-status__dot--live {
    border-color: var(--live);
    background: var(--live);
    box-shadow: 0 0 0 3px oklch(78% 0.15 150 / 0.2);
}
</style>
