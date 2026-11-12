<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ZoneController from '@/actions/App/Http/Controllers/ZoneController';
import ZoneIcon from '@/components/zones/ZoneIcon.vue';
import { useFormat } from '@/composables/useFormat';
import { useT } from '@/composables/useT';
import { latestTime, useZoneLive } from '@/composables/useZoneLive';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ZoneListItemData } from '@/types/App/Data/data.d';

defineOptions({ layout: AppLayout });

// region Props

const props = defineProps<{
    colonyName: string | null;
    zones: ZoneListItemData[];
    echo: { channel: 'uplink'; event: '.zone.updated' };
}>();

// endregion

// region Live link

/** `now` ticks every second: the header and every card age share it. */
const { live, ageLabel, now } = useZoneLive({
    echo: () => props.echo,
    zoneGuid: null,
    only: ['colonyName', 'zones'],
    updatedAt: () => latestTime(props.zones.map((zone) => zone.updatedAt)),
});

// endregion

// region Rows

const { t, tc } = useT();
const format = useFormat();

/** Problems of a zone: unknown and none never look alike. */
function problemTone(zone: ZoneListItemData): 'unknown' | 'ok' | 'bad' {
    if (zone.problemCount === null) {
        return 'unknown';
    }

    return zone.problemCount > 0 ? 'bad' : 'ok';
}

function problemText(zone: ZoneListItemData): string {
    if (zone.problemCount === null) {
        return t('zones.index.problems_unknown');
    }

    return zone.problemCount > 0
        ? tc('zones.index.problems', zone.problemCount)
        : t('zones.index.no_problem');
}

function updatedText(zone: ZoneListItemData): string {
    const at = zone.updatedAt === null ? NaN : Date.parse(zone.updatedAt);

    return Number.isNaN(at)
        ? t('zones.index.never_updated')
        : t('zones.index.updated', {
              age: format.age(Math.max(0, now.value - at)),
          });
}

// endregion
</script>

<template>
    <div class="colony min-h-full pb-16 md:pb-0">
        <div class="mx-auto max-w-[1240px] px-4 pt-6 pb-20 md:px-9">
            <!-- region Header -->
            <header
                class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3"
            >
                <div class="min-w-0">
                    <p class="zones-crumb">
                        {{ t('colony.nav.group') }}
                        <template v-if="colonyName">
                            ·
                            {{ t('zones.index.colony', { name: colonyName }) }}
                        </template>
                    </p>
                    <h1 class="zones-title">{{ t('zones.index.title') }}</h1>
                </div>
                <span
                    v-if="colonyName"
                    class="zones-live"
                    :class="{ 'zones-live--on': live }"
                    role="status"
                >
                    <span class="zones-live__dot" aria-hidden="true" />
                    {{ ageLabel }}
                </span>
            </header>
            <!-- endregion -->

            <!-- region Empty states -->
            <p v-if="!colonyName" class="zones-empty">
                {{ t('zones.index.no_colony') }}
            </p>
            <p v-else-if="zones.length === 0" class="zones-empty">
                <ZoneIcon name="map" :size="28" />
                {{ t('zones.index.empty') }}
            </p>
            <!-- endregion -->

            <!-- region List -->
            <ul v-else class="zones-list">
                <li v-for="zone in zones" :key="zone.id">
                    <Link
                        :href="ZoneController.show.url(zone.id)"
                        class="zones-card"
                    >
                        <span class="zones-card__icon">
                            <ZoneIcon name="grid" :size="20" />
                        </span>
                        <span class="min-w-0">
                            <span class="zones-card__name">{{
                                zone.name
                            }}</span>
                            <span class="zones-card__meta">
                                {{ tc('zones.index.cells', zone.cellCount) }} ·
                                {{ updatedText(zone) }}
                            </span>
                        </span>
                        <span class="zones-card__state">
                            <span
                                class="zones-problems"
                                :class="`zones-problems--${problemTone(zone)}`"
                            >
                                <ZoneIcon
                                    :name="
                                        problemTone(zone) === 'unknown'
                                            ? 'question'
                                            : problemTone(zone) === 'bad'
                                              ? 'alert'
                                              : 'check'
                                    "
                                    :size="14"
                                />
                                {{ problemText(zone) }}
                            </span>
                            <span
                                v-if="zone.problemsPartial"
                                class="zones-card__partial"
                                >{{ t('zones.index.problems_partial') }}</span
                            >
                            <span
                                v-if="zone.orphaned"
                                class="zones-card__orphan"
                                >{{ t('zones.index.orphaned') }}</span
                            >
                        </span>
                    </Link>
                </li>
            </ul>
            <!-- endregion -->
        </div>
    </div>
</template>

<style scoped>
.zones-crumb {
    color: var(--text-3);
    font-size: 13px;
}

.zones-title {
    font-family: var(--font-figure);
    font-size: 27px;
    font-weight: 700;
    line-height: 1.15;
}

/* region Live */
.zones-live {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--text-2);
    font-size: 13.5px;
}

.zones-live__dot {
    width: 8px;
    height: 8px;
    border: 1.5px solid var(--text-3);
    border-radius: 50%;
}

.zones-live--on {
    color: var(--text);
}

.zones-live--on .zones-live__dot {
    border-color: var(--live);
    background: var(--live);
    box-shadow: 0 0 0 3px oklch(78% 0.15 150 / 0.2);
}
/* endregion */

.zones-empty {
    display: grid;
    gap: 10px;
    place-items: center;
    max-width: 60ch;
    margin: 40px auto 0;
    padding: 40px 20px;
    border: 1px dashed var(--line);
    border-radius: 8px;
    color: var(--text-2);
    text-align: center;
}

/* region List */
.zones-list {
    display: grid;
    gap: 8px;
    margin-top: 22px;
}

.zones-card {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 6px 14px;
    align-items: center;
    padding: 12px 14px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--ink-1);
    color: var(--text);
    text-decoration: none;
    transition:
        border-color 160ms ease-out,
        background-color 160ms ease-out;
}

@media (min-width: 640px) {
    .zones-card {
        grid-template-columns: auto minmax(0, 1fr) auto;
    }
}

.zones-card:hover,
.zones-card:focus-visible {
    border-color: var(--text-3);
    background: var(--ink-2);
}

.zones-card__icon {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: oklch(72% 0.145 52 / 0.14);
    color: var(--color-oni-amber);
}

.zones-card__name {
    display: block;
    font-size: 16px;
    font-weight: 600;
}

.zones-card__meta {
    display: block;
    color: var(--text-3);
    font-size: 13px;
}

.zones-card__state {
    display: flex;
    flex-wrap: wrap;
    grid-column: 2;
    gap: 6px 10px;
    align-items: center;
    font-size: 13px;
}

@media (min-width: 640px) {
    .zones-card__state {
        grid-column: auto;
        justify-content: flex-end;
    }
}

.zones-problems {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
}

.zones-problems--ok {
    color: var(--ok);
}

.zones-problems--bad {
    color: var(--bad);
}

.zones-problems--unknown {
    color: var(--text-3);
}

.zones-card__partial {
    color: var(--text-3);
    font-style: italic;
}

.zones-card__orphan {
    padding: 2px 8px;
    border-radius: 99px;
    background: oklch(80% 0.14 62 / 0.14);
    color: var(--warn);
    font-size: 12px;
}
/* endregion */
</style>
