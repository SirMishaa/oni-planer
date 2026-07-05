<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Badge from '@/components/ui/Badge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    ElementShowData,
    ThermalPropertyData,
} from '@/types/App/Data/data';

defineOptions({ layout: AppLayout });

type ElementState = 'solid' | 'liquid' | 'gas' | 'special';

defineProps<{ element: ElementShowData }>();

const stateMonogram: Record<ElementState, { bg: string; text: string }> = {
    solid: { bg: 'bg-state-solid', text: 'text-[oklch(16%_0.06_52)]' },
    liquid: { bg: 'bg-state-liquid', text: 'text-[oklch(98%_0.01_222)]' },
    gas: { bg: 'bg-state-gas', text: 'text-[oklch(16%_0.06_142)]' },
    special: { bg: 'bg-state-special', text: 'text-[oklch(98%_0.01_290)]' },
};

const ELEMENTS_WITHOUT_ICON = new Set([
    'COMPOSITION',
    'Creature',
    'Vacuum',
    'Void',
]);

function hasIcon(elementId: string): boolean {
    return !ELEMENTS_WITHOUT_ICON.has(elementId);
}

function toState(state: string): ElementState {
    const valid: ElementState[] = ['solid', 'liquid', 'gas', 'special'];
    return valid.includes(state as ElementState)
        ? (state as ElementState)
        : 'special';
}

function displayName(name: Record<string, string>, fallback: string): string {
    return name['en'] ?? fallback;
}

function toSlug(elementId: string): string {
    return elementId.replace(
        /([A-Z])/g,
        (c, _, i) => (i > 0 ? '-' : '') + c.toLowerCase(),
    );
}

function formatTemp(k: number | null): string {
    return k !== null ? `${(k - 273.15).toFixed(1)} °C` : '—';
}

type PropRow = { label: string; value: string };

function thermalRows(t: ThermalPropertyData): PropRow[] {
    const rows: PropRow[] = [
        {
            label: 'Température par défaut',
            value: formatTemp(t.default_temperature),
        },
        {
            label: 'Capacité thermique',
            value: `${t.specific_heat_capacity} DTU/g/°C`,
        },
        {
            label: 'Conductivité',
            value: `${t.thermal_conductivity} DTU/m/s/°C`,
        },
    ];
    if (t.low_temp !== null)
        rows.splice(1, 0, {
            label: 'Température basse',
            value: formatTemp(t.low_temp),
        });
    if (t.high_temp !== null)
        rows.splice(2, 0, {
            label: 'Température haute',
            value: formatTemp(t.high_temp),
        });
    return rows;
}
</script>

<template>
    <div class="min-h-full pb-16 md:pb-0">
        <!-- Header -->
        <header
            class="sticky top-0 z-10 border-b border-[oklch(82%_0.025_75)] bg-[oklch(94%_0.015_75)]/90 backdrop-blur-sm dark:border-[oklch(32%_0.020_250)] dark:bg-[oklch(22%_0.016_250)]/95"
        >
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-5 py-4">
                <Link
                    href="/"
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[oklch(42%_0.015_250)] transition-colors hover:bg-[oklch(88%_0.01_250)] hover:text-[oklch(16%_0.012_250)] dark:text-[oklch(66%_0.012_260)] dark:hover:bg-[oklch(30%_0.015_260)] dark:hover:text-[oklch(90%_0.008_260)]"
                    aria-label="Retour aux éléments"
                >
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path
                            d="M9 2L4 7L9 12"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </Link>
                <span
                    class="text-xs text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                    >Éléments</span
                >
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-5 py-8">
            <!-- Identity -->
            <div class="flex items-start gap-4">
                <div
                    class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl text-sm font-bold select-none"
                    :class="[
                        stateMonogram[toState(element.state)].bg,
                        stateMonogram[toState(element.state)].text,
                    ]"
                >
                    <img
                        v-if="hasIcon(element.element_id)"
                        :src="`/assets/game/elements/${element.element_id}.webp`"
                        :alt="displayName(element.name, element.element_id)"
                        class="h-full w-full object-cover"
                    />
                    <span v-else>{{
                        displayName(element.name, element.element_id)
                            .split(' ')
                            .slice(0, 2)
                            .map((w) => w[0] ?? '')
                            .join('')
                            .toUpperCase()
                    }}</span>
                </div>
                <div>
                    <h1 class="text-xl leading-tight font-semibold">
                        {{ displayName(element.name, element.element_id) }}
                    </h1>
                    <div class="mt-1 flex items-center gap-2">
                        <code
                            class="font-mono text-xs text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                            >{{ element.element_id }}</code
                        >
                        <Badge :variant="toState(element.state)">{{
                            element.state
                        }}</Badge>
                        <Badge
                            v-if="element.material_category"
                            variant="neutral"
                            >{{ element.material_category }}</Badge
                        >
                    </div>
                </div>
            </div>

            <!-- Description -->
            <p
                v-if="element.description?.['en']"
                class="mt-5 text-sm leading-relaxed text-[oklch(38%_0.015_250)] dark:text-[oklch(68%_0.010_260)]"
            >
                {{ element.description['en'] }}
            </p>

            <!-- Basic properties -->
            <section class="mt-8">
                <h2
                    class="mb-3 text-xs font-semibold tracking-widest text-[oklch(42%_0.015_250)] uppercase dark:text-[oklch(66%_0.012_260)]"
                >
                    Propriétés
                </h2>
                <dl
                    class="divide-y divide-[oklch(90%_0.008_250)] dark:divide-[oklch(30%_0.012_260)]"
                >
                    <div class="flex justify-between py-2.5 text-sm">
                        <dt
                            class="text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                        >
                            Masse molaire
                        </dt>
                        <dd class="font-mono">
                            {{ element.molar_mass }} g/mol
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5 text-sm">
                        <dt
                            class="text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                        >
                            Toxicité
                        </dt>
                        <dd class="font-mono">{{ element.toxicity }}</dd>
                    </div>
                    <div
                        v-if="element.tags.length > 0"
                        class="flex items-start justify-between gap-4 py-2.5 text-sm"
                    >
                        <dt
                            class="text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                        >
                            Tags
                        </dt>
                        <dd class="flex flex-wrap justify-end gap-1">
                            <Badge
                                v-for="tag in element.tags"
                                :key="tag"
                                variant="neutral"
                                >{{ tag }}</Badge
                            >
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Thermal properties -->
            <section v-if="element.thermal" class="mt-8">
                <h2
                    class="mb-3 text-xs font-semibold tracking-widest text-[oklch(42%_0.015_250)] uppercase dark:text-[oklch(66%_0.012_260)]"
                >
                    Thermique
                </h2>
                <dl
                    class="divide-y divide-[oklch(90%_0.008_250)] dark:divide-[oklch(30%_0.012_260)]"
                >
                    <div
                        v-for="row in thermalRows(element.thermal)"
                        :key="row.label"
                        class="flex justify-between py-2.5 text-sm"
                    >
                        <dt
                            class="text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                        >
                            {{ row.label }}
                        </dt>
                        <dd class="font-mono">{{ row.value }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Phase transitions -->
            <section
                v-if="
                    element.low_temp_transition_target ||
                    element.high_temp_transition_target
                "
                class="mt-8"
            >
                <h2
                    class="mb-3 text-xs font-semibold tracking-widest text-[oklch(42%_0.015_250)] uppercase dark:text-[oklch(66%_0.012_260)]"
                >
                    Transitions de phase
                </h2>
                <div class="flex items-center gap-2 text-sm">
                    <Link
                        v-if="element.low_temp_transition_target"
                        :href="`/elements/${toSlug(element.low_temp_transition_target)}`"
                        class="rounded-lg border border-[oklch(88%_0.01_250)] px-3 py-2 font-mono text-xs transition-colors hover:border-state-liquid hover:text-state-liquid dark:border-[oklch(35%_0.015_260)] dark:hover:border-state-liquid dark:hover:text-state-liquid"
                    >
                        {{ element.low_temp_transition_target }}
                    </Link>
                    <span
                        v-if="
                            element.low_temp_transition_target &&
                            element.high_temp_transition_target
                        "
                        class="text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                        >←</span
                    >
                    <span
                        class="rounded-lg border-2 px-3 py-2 font-mono text-xs font-semibold"
                        :class="`border-state-${toState(element.state)} text-state-${toState(element.state)}`"
                    >
                        {{ element.element_id }}
                    </span>
                    <span
                        v-if="
                            element.low_temp_transition_target &&
                            element.high_temp_transition_target
                        "
                        class="text-[oklch(42%_0.015_250)] dark:text-[oklch(66%_0.012_260)]"
                        >→</span
                    >
                    <Link
                        v-if="element.high_temp_transition_target"
                        :href="`/elements/${toSlug(element.high_temp_transition_target)}`"
                        class="rounded-lg border border-[oklch(88%_0.01_250)] px-3 py-2 font-mono text-xs transition-colors hover:border-state-solid hover:text-state-solid dark:border-[oklch(35%_0.015_260)] dark:hover:border-state-solid dark:hover:text-state-solid"
                    >
                        {{ element.high_temp_transition_target }}
                    </Link>
                </div>
            </section>
        </main>
    </div>
</template>
