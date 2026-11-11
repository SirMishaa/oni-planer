<script setup lang="ts">
import { computed, ref } from 'vue';
import { entries, isContainer, summarize } from '@/components/uplink/jsonTree';
import { useT } from '@/composables/useT';

// region Props

const props = withDefaults(
    defineProps<{
        value: unknown;
        /** Depth of `value` in the whole document (0 for the root). */
        depth?: number;
        /** Nodes shallower than this start open; 1 shows the top-level keys only. */
        openDepth?: number;
    }>(),
    { depth: 0, openDepth: 1 },
);

// endregion

// region Children

/** Children rendered per step, so a 2 000-item array stays responsive. */
const PAGE_SIZE = 100;

const { tc } = useT();

const limit = ref(PAGE_SIZE);

const total = computed(() => {
    const summary = summarize(props.value);

    return summary.kind === 'literal' ? 0 : summary.count;
});

const shown = computed(() =>
    isContainer(props.value) ? entries(props.value, limit.value) : [],
);

function summaryText(value: unknown): string {
    const summary = summarize(value);

    if (summary.kind === 'literal') {
        return summary.text;
    }

    return summary.kind === 'array'
        ? `[ ${tc('colony.inspector.items', summary.count)} ]`
        : `{ ${tc('colony.inspector.keys', summary.count)} }`;
}

function literalKind(value: unknown): string {
    return value === null ? 'null' : typeof value;
}

// endregion

// region Folding

/** Keys whose open state differs from the default for this depth. */
const toggled = ref(new Set<string>());

const openByDefault = computed(() => props.depth + 1 < props.openDepth);

function isOpen(key: string): boolean {
    return openByDefault.value !== toggled.value.has(key);
}

function toggle(key: string): void {
    const next = new Set(toggled.value);

    if (!next.delete(key)) {
        next.add(key);
    }

    toggled.value = next;
}

// endregion
</script>

<template>
    <ul v-if="isContainer(value)" class="json-tree">
        <li v-for="entry in shown" :key="entry.key">
            <!-- region Container node -->
            <template v-if="isContainer(entry.value)">
                <button
                    type="button"
                    class="json-tree__node"
                    :aria-expanded="isOpen(entry.key)"
                    @click="toggle(entry.key)"
                >
                    <span class="json-tree__chevron" aria-hidden="true" />
                    <span class="json-tree__key">{{ entry.key }}</span>
                    <span class="json-tree__summary">{{
                        summaryText(entry.value)
                    }}</span>
                </button>
                <JsonTree
                    v-if="isOpen(entry.key)"
                    :value="entry.value"
                    :depth="depth + 1"
                    :open-depth="openDepth"
                />
            </template>
            <!-- endregion -->

            <!-- region Leaf -->
            <div v-else class="json-tree__leaf">
                <span class="json-tree__key">{{ entry.key }}</span
                ><span class="json-tree__punct" aria-hidden="true">: </span
                ><span
                    class="json-tree__literal"
                    :data-kind="literalKind(entry.value)"
                    >{{ summaryText(entry.value) }}</span
                >
            </div>
            <!-- endregion -->
        </li>

        <!-- region Show more -->
        <li v-if="total > limit">
            <button
                type="button"
                class="json-tree__more"
                @click="limit += PAGE_SIZE"
            >
                {{ tc('colony.inspector.more', total - limit) }}
            </button>
        </li>
        <!-- endregion -->
    </ul>
    <span
        v-else
        class="json-tree__literal json-tree__literal--root"
        :data-kind="literalKind(value)"
        >{{ summaryText(value) }}</span
    >
</template>

<style scoped>
.json-tree {
    font-family:
        ui-monospace, 'JetBrains Mono', 'Cascadia Code', Menlo, Consolas,
        monospace;
    font-size: 13px;
    line-height: 1.5;
}

.json-tree .json-tree {
    margin-left: 7px;
    padding-left: 13px;
    border-left: 1px solid var(--line);
}

.json-tree__node,
.json-tree__leaf {
    display: flex;
    align-items: baseline;
    min-height: 26px;
    padding-block: 2px;
}

.json-tree__node {
    gap: 8px;
    width: 100%;
    padding-inline: 2px 6px;
    border-radius: 4px;
    text-align: left;
    cursor: pointer;
    transition: background-color 120ms ease-out;
}

.json-tree__node:hover {
    background: var(--ink-2);
}

.json-tree__leaf {
    flex-wrap: wrap;
    padding-left: 22px;
}

.json-tree__chevron {
    width: 0;
    height: 0;
    flex: none;
    align-self: center;
    margin-inline: 3px 1px;
    border-block: 4.5px solid transparent;
    border-left: 6px solid var(--text-3);
    transition: transform 160ms cubic-bezier(0.25, 1, 0.5, 1);
}

.json-tree__node[aria-expanded='true'] .json-tree__chevron {
    transform: rotate(90deg);
}

.json-tree__key {
    color: var(--text);
    font-weight: 600;
}

.json-tree__summary,
.json-tree__punct {
    color: var(--text-3);
}

.json-tree__literal {
    min-width: 0;
    overflow-wrap: anywhere;
    color: var(--text);
}

.json-tree__literal[data-kind='string'] {
    color: var(--wild);
}

.json-tree__literal[data-kind='boolean'],
.json-tree__literal[data-kind='null'] {
    color: var(--warn);
}

.json-tree__more {
    min-height: 28px;
    margin: 4px 0 4px 22px;
    padding: 2px 10px;
    border: 1px solid var(--line);
    border-radius: 6px;
    color: var(--text-2);
    font-family: var(--font-text);
    font-size: 13px;
    cursor: pointer;
}

.json-tree__more:hover {
    border-color: var(--text-3);
    color: var(--text);
}

@media (prefers-reduced-motion: reduce) {
    .json-tree__chevron {
        transition: none;
    }
}
</style>
