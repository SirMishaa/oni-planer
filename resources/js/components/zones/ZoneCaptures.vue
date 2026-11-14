<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ZoneCaptureController from '@/actions/App/Http/Controllers/ZoneCaptureController';
import ZoneCaptureView from '@/components/zones/ZoneCaptureView.vue';
import ZoneIcon from '@/components/zones/ZoneIcon.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import type {
    ZoneBoxData,
    ZoneCaptureData,
    ZoneCaptureRequestData,
} from '@/types/App/Data/data.d';

// region Props

const props = defineProps<{
    zoneId: number;
    /** The map's box (the capture frames the same cells), null before the layout. */
    box: ZoneBoxData | null;
    captures: ZoneCaptureData[];
    captureRequest: ZoneCaptureRequestData | null;
    /** The game is linked and sending this zone: a capture can be asked. */
    live: boolean;
}>();

// endregion

// region Selection

const { t } = useT();
const format = useFormat();

/** The capture shown large; the latest unless the player picked another one. */
const selectedId = ref<number | null>(null);

const selected = computed(
    () =>
        props.captures.find((capture) => capture.id === selectedId.value) ??
        props.captures[0] ??
        null,
);

/** A new capture arriving shows itself. */
watch(
    () => props.captures[0]?.id,
    () => {
        selectedId.value = null;
    },
);

function describe(capture: ZoneCaptureData): string {
    return [
        format.age(Date.now() - Date.parse(capture.takenAt)),
        t('zones.captures.cycle', { cycle: String(capture.cycle) }),
        t(`zones.captures.trigger.${capture.trigger}`),
    ].join(' · ');
}

// endregion

// region Capture request

const sending = ref(false);

const pending = computed(
    () => sending.value || props.captureRequest?.status === 'pending',
);

/** Why the latest request did not give a capture, while no newer capture came. */
const refusal = computed(() => {
    const request = props.captureRequest;
    const latest = props.captures[0];

    if (request === null || !['refused', 'expired'].includes(request.status)) {
        return null;
    }

    if (latest && Date.parse(latest.takenAt) >= Date.parse(request.issuedAt)) {
        return null;
    }

    return request.reason ?? t(`zones.captures.status.${request.status}`);
});

function capture(): void {
    sending.value = true;
    router.post(
        ZoneCaptureController.store(props.zoneId).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}

// endregion
</script>

<template>
    <div class="zone-captures">
        <!-- region Toolbar -->
        <div class="zone-captures__bar">
            <button
                type="button"
                class="zone-captures__button"
                :disabled="!live || pending"
                :title="live ? undefined : t('zones.captures.not_linked')"
                @click="capture"
            >
                <ZoneIcon name="camera" />{{
                    pending
                        ? t('zones.captures.pending')
                        : t('zones.captures.capture')
                }}
            </button>
            <span v-if="!live" class="zone-captures__note">{{
                t('zones.captures.not_linked')
            }}</span>
            <span
                v-else-if="refusal"
                class="zone-captures__note zone-captures__note--bad"
                role="status"
                >{{ t('zones.captures.refused', { reason: refusal }) }}</span
            >
        </div>
        <!-- endregion -->

        <div class="zone-captures__body">
            <!-- region Selected capture -->
            <ZoneCaptureView
                v-if="selected"
                :capture="selected"
                :box="box"
                :cell-px="null"
                :alt="t('zones.captures.alt', { when: describe(selected) })"
            />
            <p v-else class="zone-captures__empty">
                <ZoneIcon name="camera" :size="28" />
                {{ t('zones.captures.empty') }}
            </p>
            <!-- endregion -->

            <!-- region Side: caption and strip -->
            <aside v-if="selected" class="zone-captures__side">
                <p class="zone-captures__caption">
                    <span>{{ describe(selected) }}</span>
                    <span
                        class="zone-captures__badge"
                        :class="{
                            'zone-captures__badge--current':
                                selected.matchesLayout,
                        }"
                        >{{
                            selected.matchesLayout
                                ? t('zones.captures.current_layout')
                                : t('zones.captures.older_layout')
                        }}</span
                    >
                </p>
                <ol v-if="captures.length > 1" class="zone-captures__strip">
                    <li v-for="item in captures" :key="item.id">
                        <button
                            type="button"
                            class="zone-captures__thumb"
                            :aria-pressed="item.id === selected.id"
                            :title="describe(item)"
                            @click="selectedId = item.id"
                        >
                            <img :src="item.url" alt="" loading="lazy" />
                        </button>
                    </li>
                </ol>
            </aside>
            <!-- endregion -->
        </div>
    </div>
</template>

<style scoped>
.zone-captures {
    min-width: 0;
}

/* region Toolbar (the height of the map's layer bar, so both tabs line up) */
.zone-captures__bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    padding-block: 10px;
}

.zone-captures__button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 32px;
    padding: 4px 14px;
    border: 1px solid var(--line);
    border-radius: 99px;
    background: var(--ink-2);
    color: var(--text);
    font-size: 13px;
}

.zone-captures__button:hover:not(:disabled) {
    background: var(--ink-3);
}

.zone-captures__button:disabled {
    color: var(--text-3);
    cursor: not-allowed;
}

.zone-captures__note {
    color: var(--text-3);
    font-size: 13px;
}

.zone-captures__note--bad {
    color: var(--bad);
}
/* endregion */

/* region Body: the map tab's grid */
.zone-captures__body {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 24px;
    align-items: start;
    margin-top: 4px;
}

@media (min-width: 1024px) {
    .zone-captures__body {
        grid-template-columns: minmax(0, 1fr) 310px;
    }
}

.zone-captures__empty {
    display: grid;
    gap: 10px;
    place-items: center;
    padding: 56px 20px;
    border: 1px dashed var(--line);
    border-radius: 8px;
    color: var(--text-2);
    text-align: center;
}
/* endregion */

/* region Side */
.zone-captures__side {
    display: grid;
    gap: 12px;
}

.zone-captures__caption {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin: 0;
    color: var(--text-2);
    font-size: 13px;
}

.zone-captures__badge {
    padding: 2px 8px;
    border-radius: 99px;
    background: var(--ink-3);
    color: var(--text-2);
    font-size: 12px;
}

.zone-captures__badge--current {
    color: var(--ok);
}

.zone-captures__strip {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.zone-captures__thumb {
    display: block;
    width: 100%;
    padding: 0;
    border: 2px solid transparent;
    border-radius: 6px;
    background: var(--ink-1);
    overflow: hidden;
}

.zone-captures__thumb[aria-pressed='true'] {
    border-color: var(--color-oni-amber);
}

.zone-captures__thumb img {
    display: block;
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
}
/* endregion */
</style>
