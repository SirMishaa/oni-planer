<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    toRef,
    useTemplateRef,
} from 'vue';
import ColonyController from '@/actions/App/Http/Controllers/ColonyController';
import JsonTree from '@/components/uplink/JsonTree.vue';
import ColonyEmptyState from '@/components/viability/ColonyEmptyState.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import { useUplink } from '@/composables/useUplink';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    ColonyStatusData,
    UplinkMessageData,
} from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

// region Props

const props = defineProps<{
    status: ColonyStatusData;
    messages: UplinkMessageData[];
    selected: { id: number; payload: string } | null;
}>();

// endregion

const { t, tc } = useT();
const format = useFormat();

// region Live list and pause

const running = ref(true);

const { live, ageLabel } = useUplink(
    toRef(props, 'status'),
    ['messages', 'status'],
    running,
);

function togglePause(): void {
    running.value = !running.value;

    if (running.value) {
        router.reload({ only: ['messages', 'status'] });
    }
}

// endregion

// region Formatting

function formatTime(iso: string): string {
    const date = new Date(iso);
    const today = new Date().toDateString() === date.toDateString();

    return new Intl.DateTimeFormat(
        format.locale(),
        today
            ? { timeStyle: 'medium' }
            : { dateStyle: 'short', timeStyle: 'medium' },
    ).format(date);
}

function formatFullTime(iso: string): string {
    return new Intl.DateTimeFormat(format.locale(), {
        dateStyle: 'long',
        timeStyle: 'medium',
    }).format(new Date(iso));
}

function sizeLabel(bytes: number): string {
    return t('colony.inspector.size', {
        size: format.number(bytes / 1024, 1),
    });
}

function colonyLabel(message: UplinkMessageData): string {
    const name = message.colonyName ?? t('colony.inspector.no_colony');

    return message.cycle === null
        ? name
        : t('colony.inspector.summary.colony_cycle', {
              name,
              cycle: format.number(message.cycle),
          });
}

function outcomeLabel(message: UplinkMessageData): string {
    if (message.outcome === 'stored') {
        return t('colony.inspector.outcome.stored');
    }

    if (message.reason === null) {
        return t('colony.inspector.outcome.refused_plain');
    }

    const reasonKey = `colony.inspector.reason.${message.reason}`;
    const reason = t(reasonKey);

    return reason === reasonKey
        ? t('colony.inspector.outcome.refused_plain')
        : t('colony.inspector.outcome.refused', { reason });
}

function sectionErrorsLabel(message: UplinkMessageData): string {
    return tc('colony.inspector.section_errors', message.sectionErrors.length, {
        sections: format.list(message.sectionErrors),
    });
}

// endregion

// region Connection summary

const lastMessage = computed(() => props.messages[0] ?? null);

const colonySummary = computed(() => {
    if (props.status.colonyName === null) {
        return t('colony.inspector.summary.none');
    }

    return props.status.cycle === null
        ? props.status.colonyName
        : t('colony.inspector.summary.colony_cycle', {
              name: props.status.colonyName,
              cycle: format.number(props.status.cycle),
          });
});

// endregion

// region Selection

const pendingId = ref<number | null>(null);
const detail = useTemplateRef<HTMLElement>('detail');

const currentId = computed(() => pendingId.value ?? props.selected?.id ?? null);

const currentMessage = computed(
    () =>
        props.messages.find((message) => message.id === currentId.value) ??
        null,
);

function select(id: number): void {
    if (id === props.selected?.id) {
        return;
    }

    pendingId.value = id;
    router.visit(ColonyController.uplink({ query: { message: id } }), {
        preserveScroll: true,
        preserveState: true,
        only: ['selected'],
        onSuccess: () => {
            void nextTick(revealDetailOnNarrowScreens);
        },
        onFinish: () => {
            pendingId.value = null;
        },
    });
}

/** Below `lg` the payload sits under the list: bring it into view. */
function revealDetailOnNarrowScreens(): void {
    if (
        detail.value === null ||
        window.matchMedia('(min-width: 1024px)').matches
    ) {
        return;
    }

    const reduceMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    detail.value.scrollIntoView({
        block: 'start',
        behavior: reduceMotion ? 'auto' : 'smooth',
    });
    detail.value.focus({ preventScroll: true });
}

/** The payload parsed once per selection; `null` when it is not JSON. */
const parsed = computed<{ value: unknown } | null>(() => {
    if (props.selected === null) {
        return null;
    }

    try {
        return { value: JSON.parse(props.selected.payload) as unknown };
    } catch {
        return null;
    }
});

// endregion

// region Copy

const COPY_FEEDBACK_MS = 3_000;

const copyFeedback = ref<'copied' | 'copy_failed' | null>(null);
let copyFeedbackTimer: ReturnType<typeof setTimeout> | undefined;

async function copyPayload(): Promise<void> {
    if (props.selected === null) {
        return;
    }

    try {
        if (!navigator.clipboard) {
            throw new Error('Clipboard API unavailable');
        }

        await navigator.clipboard.writeText(props.selected.payload);
        copyFeedback.value = 'copied';
    } catch {
        copyFeedback.value = 'copy_failed';
    }

    clearTimeout(copyFeedbackTimer);
    copyFeedbackTimer = setTimeout(() => {
        copyFeedback.value = null;
    }, COPY_FEEDBACK_MS);
}

onBeforeUnmount(() => clearTimeout(copyFeedbackTimer));

// endregion
</script>

<template>
    <div class="colony min-h-full pb-16 md:pb-0">
        <div class="mx-auto max-w-[1240px] px-4 pt-6 pb-20 md:px-9">
            <!-- region Header -->
            <header
                class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3"
            >
                <div class="min-w-0">
                    <p class="page-crumb">{{ t('colony.nav.group') }}</p>
                    <h1 class="page-title">
                        {{ t('colony.inspector.title') }}
                    </h1>
                    <p class="page-intro">{{ t('colony.inspector.intro') }}</p>
                </div>
                <button
                    v-if="messages.length > 0"
                    type="button"
                    class="pause-button"
                    :class="{ 'pause-button--paused': !running }"
                    @click="togglePause"
                >
                    <span class="pause-button__icon" aria-hidden="true" />
                    {{
                        running
                            ? t('colony.inspector.pause')
                            : t('colony.inspector.resume')
                    }}
                </button>
            </header>

            <p
                v-if="status.state === 'newer_schema'"
                class="page-banner"
                role="status"
            >
                {{ t('colony.inspector.newer_schema') }}
            </p>
            <!-- endregion -->

            <!-- region Connection summary -->
            <section
                class="summary"
                :aria-label="t('colony.inspector.summary.label')"
            >
                <dl class="summary__list">
                    <div>
                        <dt>{{ t('colony.inspector.summary.link') }}</dt>
                        <dd class="summary__link">
                            <span
                                class="summary__dot"
                                :class="{ 'summary__dot--live': live }"
                                aria-hidden="true"
                            />
                            {{ ageLabel }}
                        </dd>
                    </div>
                    <div>
                        <dt>{{ t('colony.inspector.summary.last') }}</dt>
                        <dd>
                            <time
                                v-if="lastMessage"
                                :datetime="lastMessage.receivedAt"
                                :title="formatFullTime(lastMessage.receivedAt)"
                                >{{ formatTime(lastMessage.receivedAt) }}</time
                            >
                            <template v-else>{{
                                t('colony.inspector.summary.none')
                            }}</template>
                        </dd>
                    </div>
                    <div>
                        <dt>{{ t('colony.inspector.summary.colony') }}</dt>
                        <dd>{{ colonySummary }}</dd>
                    </div>
                    <div>
                        <dt>{{ t('colony.inspector.summary.schema') }}</dt>
                        <dd>
                            {{
                                status.schema === null
                                    ? t('colony.inspector.summary.unknown')
                                    : status.schema
                            }}
                        </dd>
                    </div>
                </dl>
                <p v-if="!running" class="summary__paused" role="status">
                    {{ t('colony.inspector.paused') }}
                </p>
            </section>
            <!-- endregion -->

            <!-- region Empty state -->
            <ColonyEmptyState v-if="messages.length === 0" state="never" />
            <!-- endregion -->

            <div
                v-else
                class="mt-8 grid grid-cols-1 gap-x-10 gap-y-10 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]"
            >
                <!-- region Message list -->
                <table class="messages min-w-0">
                    <caption class="sr-only">
                        {{
                            t('colony.inspector.list_label')
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">
                                {{ t('colony.inspector.columns.time') }}
                            </th>
                            <th scope="col">
                                {{ t('colony.inspector.columns.colony') }}
                            </th>
                            <th scope="col" class="messages__num">
                                {{ t('colony.inspector.columns.size') }}
                            </th>
                            <th scope="col">
                                {{ t('colony.inspector.columns.outcome') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="message in messages"
                            :key="message.id"
                            class="messages__row"
                            :class="{
                                'messages__row--current':
                                    message.id === currentId,
                            }"
                            @click="select(message.id)"
                        >
                            <td class="messages__time">
                                <button
                                    type="button"
                                    class="messages__select"
                                    :aria-current="
                                        message.id === currentId
                                            ? 'true'
                                            : undefined
                                    "
                                    @click.stop="select(message.id)"
                                >
                                    <time
                                        :datetime="message.receivedAt"
                                        :title="
                                            formatFullTime(message.receivedAt)
                                        "
                                        >{{
                                            formatTime(message.receivedAt)
                                        }}</time
                                    >
                                </button>
                                <span class="messages__event">{{
                                    message.event ??
                                    t('colony.inspector.no_event')
                                }}</span>
                            </td>
                            <td class="messages__colony">
                                {{ colonyLabel(message) }}
                            </td>
                            <td class="messages__num messages__size">
                                {{ sizeLabel(message.size) }}
                            </td>
                            <td class="messages__outcome">
                                <span
                                    class="outcome"
                                    :data-outcome="message.outcome"
                                    >{{ outcomeLabel(message) }}</span
                                >
                                <span
                                    v-if="message.sectionErrors.length > 0"
                                    class="outcome__sections"
                                    >{{ sectionErrorsLabel(message) }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
                <!-- endregion -->

                <!-- region Payload -->
                <section
                    ref="detail"
                    class="detail"
                    tabindex="-1"
                    :aria-busy="pendingId !== null"
                    :aria-labelledby="
                        selected ? 'uplink-detail-title' : undefined
                    "
                >
                    <p v-if="pendingId !== null" class="detail__hint">
                        {{ t('colony.inspector.detail.loading') }}
                    </p>
                    <p v-else-if="selected === null" class="detail__hint">
                        {{ t('colony.inspector.detail.pick') }}
                    </p>
                    <template v-else>
                        <div class="detail__head">
                            <div class="min-w-0">
                                <h2
                                    id="uplink-detail-title"
                                    class="detail__title"
                                >
                                    {{
                                        t('colony.inspector.detail.title', {
                                            id: selected.id,
                                        })
                                    }}
                                </h2>
                                <p v-if="currentMessage" class="detail__meta">
                                    {{
                                        t('colony.inspector.detail.meta', {
                                            time: formatFullTime(
                                                currentMessage.receivedAt,
                                            ),
                                            size: sizeLabel(
                                                currentMessage.size,
                                            ),
                                        })
                                    }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="copy-button"
                                @click="copyPayload"
                            >
                                {{ t('colony.inspector.detail.copy') }}
                            </button>
                        </div>
                        <p
                            class="detail__feedback"
                            :data-feedback="copyFeedback"
                            role="status"
                        >
                            {{
                                copyFeedback
                                    ? t(
                                          `colony.inspector.detail.${copyFeedback}`,
                                      )
                                    : ''
                            }}
                        </p>

                        <div
                            v-if="parsed"
                            class="detail__body"
                            role="region"
                            :aria-label="
                                t('colony.inspector.detail.tree_label')
                            "
                        >
                            <JsonTree
                                :key="selected.id"
                                :value="parsed.value"
                            />
                        </div>
                        <template v-else>
                            <p class="detail__raw-note">
                                {{ t('colony.inspector.detail.raw') }}
                            </p>
                            <pre
                                class="detail__body detail__raw"
                            ><code>{{ selected.payload }}</code></pre>
                        </template>
                    </template>
                </section>
                <!-- endregion -->
            </div>
        </div>
    </div>
</template>

<style scoped>
/* region Header */
.page-crumb {
    color: var(--text-3);
    font-size: 13px;
}

.page-title {
    font-family: var(--font-figure);
    font-size: 27px;
    font-weight: 700;
    line-height: 1.15;
}

.page-intro {
    max-width: 70ch;
    margin-top: 4px;
    color: var(--text-2);
}

.page-banner {
    max-width: 80ch;
    margin-top: 18px;
    padding: 10px 14px;
    border: 1px solid oklch(80% 0.14 62 / 0.55);
    border-radius: 8px;
    background: oklch(80% 0.14 62 / 0.1);
    color: var(--text);
    font-size: 14px;
}

.pause-button,
.copy-button {
    display: inline-flex;
    flex: none;
    align-items: center;
    gap: 9px;
    min-height: 36px;
    padding: 6px 14px;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition:
        border-color 160ms ease-out,
        background-color 160ms ease-out;
}

.pause-button:hover,
.copy-button:hover {
    border-color: var(--text-3);
    background: var(--ink-1);
}

/* Two bars while running (press to pause), a play triangle when paused. */
.pause-button__icon {
    width: 10px;
    height: 12px;
    border-inline: 3.5px solid currentColor;
}

.pause-button--paused {
    border-color: var(--ration);
    color: var(--ration);
}

.pause-button--paused .pause-button__icon {
    width: 0;
    border-block: 6px solid transparent;
    border-inline: 10px solid currentColor;
    border-right-width: 0;
}
/* endregion */

/* region Summary */
.summary {
    margin-top: 24px;
    padding-block: 14px;
    border-block: 1px solid var(--line);
}

.summary__list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px 24px;
}

@media (min-width: 768px) {
    .summary__list {
        grid-template-columns: repeat(4, minmax(0, auto));
        justify-content: start;
        column-gap: 48px;
    }
}

.summary dt {
    color: var(--text-3);
    font-size: 12.5px;
}

.summary dd {
    overflow-wrap: anywhere;
    font-family: var(--font-figure);
    font-size: 17px;
    font-weight: 600;
}

.summary__link {
    display: flex;
    align-items: center;
    gap: 8px;
}

.summary__dot {
    width: 8px;
    height: 8px;
    flex: none;
    border: 1.5px solid var(--text-3);
    border-radius: 50%;
}

.summary__dot--live {
    border-color: var(--live);
    background: var(--live);
    box-shadow: 0 0 0 3px oklch(78% 0.15 150 / 0.2);
}

.summary__paused {
    margin-top: 10px;
    color: var(--ration);
    font-size: 14px;
}
/* endregion */

/* region Message list */
.messages {
    width: 100%;
    align-self: start;
    border-collapse: collapse;
    font-size: 14px;
}

.messages th {
    padding: 0 10px 8px;
    border-bottom: 1px solid var(--line);
    color: var(--text-3);
    font-size: 12.5px;
    font-weight: 500;
    text-align: left;
}

.messages td {
    padding: 9px 10px;
    border-bottom: 1px solid var(--ink-3);
    vertical-align: top;
}

.messages th:first-child,
.messages td:first-child {
    padding-left: 0;
}

.messages .messages__num {
    text-align: right;
    white-space: nowrap;
}

.messages__row {
    cursor: pointer;
    transition: background-color 120ms ease-out;
}

.messages__row:hover {
    background: var(--ink-1);
}

.messages__row--current,
.messages__row--current:hover {
    background: var(--ink-2);
}

.messages__select {
    min-height: 24px;
    border-radius: 4px;
    font-family: var(--font-figure);
    font-size: 15.5px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
}

.messages__select[aria-current='true'] {
    color: var(--ration);
}

.messages__select[aria-current='true']::before {
    content: '▸ ' / '';
}

.messages__event {
    display: block;
    color: var(--text-3);
    font-size: 12.5px;
    overflow-wrap: anywhere;
}

.messages__colony {
    overflow-wrap: anywhere;
    color: var(--text-2);
}

.messages__size {
    color: var(--text-2);
}

.outcome {
    display: block;
    font-weight: 600;
}

.outcome[data-outcome='stored'] {
    color: var(--ok);
}

.outcome[data-outcome='refused'] {
    color: var(--bad);
}

.outcome__sections {
    display: block;
    color: var(--warn);
    font-size: 12.5px;
}

/* Phones: each message becomes a two-line row. */
@media (max-width: 639px) {
    .messages thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip-path: inset(50%);
    }

    .messages tbody {
        display: block;
    }

    .messages__row {
        display: grid;
        grid-template-areas:
            'time outcome'
            'colony size';
        grid-template-columns: minmax(0, 1fr) minmax(0, auto);
        gap: 2px 12px;
        padding: 10px 6px;
        border-bottom: 1px solid var(--ink-3);
    }

    .messages td {
        display: block;
        padding: 0;
        border: 0;
    }

    .messages td.messages__time {
        grid-area: time;
    }

    .messages td.messages__outcome {
        grid-area: outcome;
        text-align: right;
    }

    .messages td.messages__colony {
        grid-area: colony;
    }

    .messages td.messages__size {
        grid-area: size;
    }
}
/* endregion */

/* region Payload */
.detail {
    min-width: 0;
    align-self: start;
    scroll-margin-top: 16px;
}

.detail:focus {
    outline: none;
}

@media (min-width: 1024px) {
    .detail {
        position: sticky;
        top: 16px;
        display: flex;
        flex-direction: column;
        max-height: calc(100dvh - 32px);
    }
}

.detail__hint {
    padding-block: 14px;
    border-block: 1px solid var(--line);
    color: var(--text-3);
}

.detail__head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px 16px;
}

.detail__title {
    font-family: var(--font-figure);
    font-size: 21px;
    font-weight: 700;
    line-height: 1.2;
}

.detail__meta {
    color: var(--text-3);
    font-size: 13px;
}

.detail__feedback {
    min-height: 21px;
    margin-block: 4px;
    color: var(--ok);
    font-size: 13.5px;
}

.detail__feedback[data-feedback='copy_failed'] {
    color: var(--warn);
}

.detail__raw-note {
    margin-bottom: 8px;
    color: var(--warn);
    font-size: 14px;
}

.detail__body {
    min-height: 0;
    overflow: auto;
    padding: 10px 12px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--ink-1);
}

@media (max-width: 1023px) {
    .detail__body {
        max-height: 75vh;
    }
}

.detail__raw {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: 13px;
}
/* endregion */
</style>
