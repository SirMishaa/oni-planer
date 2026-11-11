<script setup lang="ts">
import { useT } from '@/composables/useT';

// region Props

defineProps<{
    /** `never`: Uplink has not sent anything; otherwise no colony to show. */
    state: 'never' | 'no_colony';
}>();

// endregion

const { t } = useT();
</script>

<template>
    <section class="empty-state">
        <!-- region Never connected -->
        <template v-if="state === 'never'">
            <h2 class="figure empty-state__title">
                {{ t('colony.viability.empty.never.title') }}
            </h2>
            <p>{{ t('colony.viability.empty.never.body') }}</p>
            <pre
                class="empty-state__command"
            ><code>{{ t('colony.viability.empty.never.command') }}</code></pre>
            <p class="empty-state__after">
                {{ t('colony.viability.empty.never.after') }}
            </p>
        </template>
        <!-- endregion -->

        <!-- region No colony loaded -->
        <template v-else>
            <h2 class="figure empty-state__title">
                {{ t('colony.viability.empty.no_colony.title') }}
            </h2>
            <p>{{ t('colony.viability.empty.no_colony.body') }}</p>
        </template>
        <!-- endregion -->
    </section>
</template>

<style scoped>
.empty-state {
    display: grid;
    gap: 12px;
    max-width: 62ch;
    margin-top: 28px;
    padding-block: 28px 32px;
    border-block: 1px solid var(--line);
    color: var(--text-2);
}

.empty-state__title {
    color: var(--text);
    font-size: 24px;
    font-weight: 700;
    line-height: 1.15;
}

.empty-state__command {
    overflow-x: auto;
    padding: 10px 14px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--ink-1);
    color: var(--ration);
    font-size: 14px;
}

.empty-state__after {
    color: var(--text-3);
}
</style>
