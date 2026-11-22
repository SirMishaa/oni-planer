<script setup lang="ts">
import { useT } from '@/composables/useT';
import type { ZonePlanChangeData, ZonePlanData } from '@/types/App/Data/data.d';
import { planChangeStatus } from './zonePlan';

// region Props

const props = defineProps<{
    plan: ZonePlanData;
    selectedChangeId: number | null;
    /** The server's refusal of the last move or delete; null when none. */
    error: string | null;
    /** A change whose delete is on its way. */
    busyChangeId: number | null;
}>();

const emit = defineEmits<{
    select: [changeId: number];
    delete: [changeId: number];
    'dismiss-error': [];
}>();

// endregion

// region Labels

const { t, tc } = useT();

function changeName(change: ZonePlanChangeData): string {
    return change.name ?? change.target?.label ?? change.prefabId ?? '';
}

/** What a replace takes out, shown under its name. */
function replaced(change: ZonePlanChangeData): string | null {
    return change.kind === 'replace' ? (change.target?.label ?? null) : null;
}

function isSelected(change: ZonePlanChangeData): boolean {
    return change.id === props.selectedChangeId;
}

// endregion
</script>

<template>
    <section class="zone-plan" :aria-label="t('zones.plan.heading')">
        <!-- region Header -->
        <h2 class="zone-plan__heading">
            {{ t('zones.plan.heading') }}
            <em>{{ tc('zones.plan.changes', plan.changes.length) }}</em>
        </h2>
        <p class="zone-plan__muted">{{ t('zones.plan.hint') }}</p>
        <!-- endregion -->

        <!-- region Changes -->
        <ul class="zone-plan__list">
            <li
                v-for="change in plan.changes"
                :key="change.id"
                class="zone-plan__item"
                :class="{ 'zone-plan__item--selected': isSelected(change) }"
                :data-plan-item="change.id"
            >
                <button
                    type="button"
                    class="zone-plan__pick"
                    :aria-expanded="isSelected(change)"
                    @click="emit('select', change.id)"
                >
                    <span class="zone-plan__title">
                        <span class="zone-plan__kind">{{
                            t(`zones.plan.kinds.${change.kind}`)
                        }}</span>
                        {{ changeName(change) }}
                    </span>
                    <span
                        class="zone-plan__state"
                        :class="`zone-plan__state--${planChangeStatus(change)}`"
                        >{{
                            t(`zones.plan.states.${planChangeStatus(change)}`)
                        }}</span
                    >
                    <span v-if="replaced(change)" class="zone-plan__muted">
                        {{
                            t('zones.plan.replaces', {
                                name: replaced(change) ?? '',
                            })
                        }}
                    </span>
                    <span v-if="change.movedByPlayer" class="zone-plan__muted">
                        {{ t('zones.plan.moved') }}
                    </span>
                </button>
                <div v-if="isSelected(change)" class="zone-plan__detail">
                    <p class="zone-plan__note">{{ change.note }}</p>
                    <button
                        type="button"
                        class="zone-plan__delete"
                        data-test="zone-plan-delete"
                        :disabled="busyChangeId === change.id"
                        @click="emit('delete', change.id)"
                    >
                        {{ t('zones.plan.delete') }}
                    </button>
                </div>
            </li>
        </ul>
        <!-- endregion -->

        <!-- region Error toast -->
        <div v-if="error" class="zone-plan__toast" role="alert">
            <span>{{ error }}</span>
            <button
                type="button"
                class="zone-plan__dismiss"
                :aria-label="t('zones.plan.dismiss')"
                @click="emit('dismiss-error')"
            >
                ×
            </button>
        </div>
        <!-- endregion -->
    </section>
</template>

<style scoped>
.zone-plan {
    display: grid;
    gap: 8px;
    min-width: 0;
}

.zone-plan__heading {
    color: var(--text-3);
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.zone-plan__heading em {
    margin-left: 4px;
    font-style: normal;
    font-weight: 400;
    letter-spacing: normal;
    text-transform: none;
}

.zone-plan__muted {
    color: var(--text-3);
    font-size: 12.5px;
}

/* region Changes */
.zone-plan__list {
    display: grid;
    gap: 4px;
}

.zone-plan__item {
    border-radius: 6px;
    background: var(--ink-1);
}

.zone-plan__item--selected {
    background: var(--ink-2);
    box-shadow: inset 2px 0 0 var(--color-oni-amber);
}

.zone-plan__pick {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 2px 8px;
    width: 100%;
    padding: 8px 10px;
    text-align: left;
}

.zone-plan__pick > .zone-plan__muted {
    grid-column: 1 / -1;
}

.zone-plan__title {
    min-width: 0;
    color: var(--text);
    font-size: 13.5px;
}

.zone-plan__kind {
    margin-right: 4px;
    color: var(--text-3);
}

.zone-plan__state {
    --state: var(--color-oni-amber);
    align-self: start;
    padding: 0 6px;
    border: 1px solid var(--state);
    border-radius: 4px;
    color: var(--state);
    font-size: 11px;
    line-height: 18px;
    white-space: nowrap;
}

.zone-plan__state--unchecked {
    border-style: dashed;
}

.zone-plan__state--applied {
    --state: var(--ok);
}

.zone-plan__state--conflicted {
    --state: var(--bad);
}

.zone-plan__detail {
    display: grid;
    gap: 8px;
    padding: 0 10px 10px;
}

.zone-plan__note {
    color: var(--text-2);
    font-size: 13px;
    white-space: pre-line;
}

.zone-plan__delete {
    display: inline-flex;
    align-items: center;
    justify-self: start;
    gap: 6px;
    min-height: 28px;
    padding: 2px 10px;
    border: 1px solid oklch(72% 0.17 25 / 0.55);
    border-radius: 6px;
    color: var(--bad);
    font-size: 12.5px;
}

.zone-plan__delete:hover {
    background: oklch(72% 0.17 25 / 0.12);
}

.zone-plan__delete:disabled {
    opacity: 0.5;
}
/* endregion */

/* region Error toast */
.zone-plan__toast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 40;
    display: flex;
    align-items: start;
    gap: 10px;
    max-width: 380px;
    padding: 10px 12px;
    border: 1px solid oklch(72% 0.17 25 / 0.55);
    border-radius: 8px;
    background: oklch(22% 0.03 240 / 0.97);
    box-shadow: 0 6px 20px oklch(0% 0 0 / 0.4);
    color: var(--text);
    font-size: 13px;
}

.zone-plan__dismiss {
    color: var(--text-3);
    font-size: 16px;
    line-height: 1;
}
/* endregion */
</style>
