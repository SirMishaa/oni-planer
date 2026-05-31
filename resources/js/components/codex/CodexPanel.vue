<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import CodexCategoryIcon from '@/components/codex/CodexCategoryIcon.vue';
import {
    categoryMeta,
    type CodexTone,
    pickLocale,
    useCodex,
} from '@/composables/useCodex';

// region Block types
type TextBlock = { type: 'text'; style: string; text: Record<string, string> };
type ImageBlock = {
    type: 'image';
    sprite: string | null;
    height: number | null;
};
type DividerBlock = { type: 'divider' };
type Block = TextBlock | ImageBlock | DividerBlock;
type Container = { layout: string; lock_id: string | null; blocks: Block[] };
// endregion

const codex = useCodex();
const {
    isOpen,
    isPinned,
    isDesktop,
    width,
    mode,
    selectedCategory,
    search,
    indexLoading,
    indexError,
    article,
    activeId,
    articleLoading,
    articleError,
    searching,
    tiles,
    selectedItems,
    searchResults,
    resultCount,
    dockActive,
} = codex;

const searchInput = ref<HTMLInputElement | null>(null);

// region Tones
const TONE_CHIP: Record<CodexTone, string> = {
    solid: 'bg-[oklch(90%_0.045_52)] text-[oklch(34%_0.12_52)] dark:bg-[oklch(24%_0.065_52)] dark:text-[oklch(76%_0.13_52)]',
    liquid: 'bg-[oklch(88%_0.04_222)] text-[oklch(28%_0.12_222)] dark:bg-[oklch(19%_0.07_222)] dark:text-[oklch(70%_0.15_222)]',
    gas: 'bg-[oklch(88%_0.045_142)] text-[oklch(26%_0.1_142)] dark:bg-[oklch(19%_0.07_142)] dark:text-[oklch(72%_0.14_142)]',
    special:
        'bg-[oklch(88%_0.04_290)] text-[oklch(28%_0.13_290)] dark:bg-[oklch(19%_0.08_290)] dark:text-[oklch(70%_0.17_290)]',
    neutral:
        'bg-[oklch(88%_0.008_250)] text-[oklch(38%_0.015_250)] dark:bg-[oklch(26%_0.013_260)] dark:text-[oklch(64%_0.012_260)]',
};

const TONE_DOT: Record<CodexTone, string> = {
    solid: 'bg-state-solid',
    liquid: 'bg-state-liquid',
    gas: 'bg-state-gas',
    special: 'bg-state-special',
    neutral: 'bg-[oklch(60%_0.01_250)]',
};

const LINK_TONE: Record<string, CodexTone> = {
    building: 'solid',
    element: 'liquid',
    critter: 'special',
    plant: 'gas',
    geyser: 'liquid',
};

const LINK_LABEL: Record<string, string> = {
    building: 'Bâtiment',
    element: 'Élément',
    critter: 'Créature',
    plant: 'Plante',
    geyser: 'Geyser',
};
// endregion

// region View state
const bodyView = computed<'home' | 'results' | 'category' | 'article'>(() => {
    if (mode.value === 'article') return 'article';
    if (searching.value) return 'results';
    if (mode.value === 'category') return 'category';
    return 'home';
});

const selectedMeta = computed(() =>
    selectedCategory.value ? categoryMeta(selectedCategory.value) : null,
);

const articleTitle = computed(() =>
    article.value
        ? pickLocale(article.value.title, article.value.codex_id)
        : '',
);

const articleMeta = computed(() =>
    article.value ? categoryMeta(article.value.category) : null,
);

const containers = computed<Container[]>(
    () => (article.value?.content ?? []) as Container[],
);

const panelStyle = computed(() => ({
    width: isDesktop.value ? `${width.value}px` : '100%',
}));
// endregion

// region Block helpers
function textClasses(style: string): string {
    switch (style) {
        case 'Title':
            return 'text-[15px] font-semibold tracking-tight';
        case 'Heading':
        case 'Subtitle':
            return 'mt-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-[oklch(52%_0.03_60)] dark:text-[oklch(64%_0.02_65)]';
        default:
            return 'text-[13px] leading-relaxed text-[oklch(32%_0.02_60)] dark:text-[oklch(82%_0.012_65)]';
    }
}

function isText(block: Block): block is TextBlock {
    return block.type === 'text';
}
function isImage(block: Block): block is ImageBlock {
    return block.type === 'image';
}
// endregion

// region Commands
function onNavigate(): void {
    if (!isPinned.value) codex.close();
}
// endregion

// region Open focus + keyboard
watch(isOpen, (open) => {
    if (open && mode.value !== 'article') {
        void nextTick(() => searchInput.value?.focus());
    }
});

function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    return (
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.isContentEditable
    );
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === '?' && !isTypingTarget(event.target)) {
        event.preventDefault();
        codex.toggle();
        return;
    }
    if (event.key === 'Escape' && isOpen.value && !isPinned.value) {
        codex.close();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
// endregion

// region Resize (docked rail)
const resizing = ref(false);

function startResize(event: MouseEvent): void {
    event.preventDefault();
    resizing.value = true;
    window.addEventListener('mousemove', onResize);
    window.addEventListener('mouseup', stopResize);
}
function onResize(event: MouseEvent): void {
    codex.setWidth(window.innerWidth - event.clientX);
}
function stopResize(): void {
    resizing.value = false;
    window.removeEventListener('mousemove', onResize);
    window.removeEventListener('mouseup', stopResize);
}
onBeforeUnmount(stopResize);
// endregion
</script>

<template>
    <Teleport to="body">
        <!-- region Languette -->
        <Transition name="codex-tab">
            <button
                v-if="!isOpen"
                type="button"
                class="codex-tab group fixed top-1/2 right-0 z-30 flex -translate-y-1/2 flex-col items-center gap-2 rounded-l-lg border border-r-0 border-shell-border bg-shell py-4 pr-1.5 pl-2 text-shell-muted shadow-lg transition-[transform,color] duration-200 ease-out hover:text-oni-amber"
                title="Ouvrir le Codex (?)"
                @click="codex.open()"
            >
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 26 26"
                    fill="none"
                    class="transition-colors group-hover:text-oni-amber"
                >
                    <path
                        d="M13 2.5 L22 7.5 L22 17.5 L13 22.5 L4 17.5 L4 7.5 Z"
                        stroke="currentColor"
                        stroke-width="1.4"
                        stroke-linejoin="round"
                    />
                    <circle cx="13" cy="13" r="2" fill="currentColor" />
                </svg>
                <span
                    class="text-[10px] font-semibold tracking-[0.22em] uppercase [writing-mode:vertical-rl]"
                    >Codex</span
                >
            </button>
        </Transition>
        <!-- endregion -->

        <!-- region Scrim -->
        <Transition name="codex-scrim">
            <div
                v-if="isOpen && !dockActive"
                class="fixed inset-0 z-30 bg-[oklch(15%_0.02_250)]/45 backdrop-blur-[1px]"
                @click="codex.close()"
            />
        </Transition>
        <!-- endregion -->

        <!-- region Panel -->
        <aside
            class="fixed inset-y-0 right-0 z-40 flex max-w-full flex-col border-l border-shell-border bg-[oklch(96%_0.012_75)] shadow-2xl transition-transform duration-[320ms] ease-[cubic-bezier(0.22,1,0.36,1)] dark:bg-[oklch(20%_0.016_250)]"
            :class="isOpen ? 'translate-x-0' : 'translate-x-full'"
            :style="panelStyle"
            aria-label="Codex"
        >
            <div
                v-if="dockActive"
                class="absolute inset-y-0 left-0 z-10 w-1.5 cursor-col-resize transition-colors hover:bg-oni-amber/40"
                :class="resizing ? 'bg-oni-amber/60' : ''"
                @mousedown="startResize"
            />

            <!-- region Chrome header -->
            <header
                class="relative shrink-0 border-b border-shell-border bg-shell"
            >
                <div class="flex h-14 items-center gap-2.5 px-3.5">
                    <button
                        v-if="bodyView === 'article' || bodyView === 'category'"
                        type="button"
                        class="flex h-7 items-center gap-1.5 rounded px-1.5 text-[11px] font-semibold tracking-wide uppercase transition-colors"
                        :class="
                            bodyView === 'category' && selectedMeta
                                ? 'text-shell-text hover:bg-shell-hover'
                                : 'text-shell-muted hover:bg-shell-hover hover:text-shell-text'
                        "
                        @click="codex.goBack()"
                    >
                        <svg
                            width="11"
                            height="11"
                            viewBox="0 0 12 12"
                            fill="none"
                        >
                            <path
                                d="M7.5 2.5L4 6l3.5 3.5"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <template
                            v-if="bodyView === 'category' && selectedMeta"
                        >
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :class="TONE_DOT[selectedMeta.tone]"
                            />
                            {{ selectedMeta.label }}
                        </template>
                        <template v-else>Retour</template>
                    </button>

                    <template v-else>
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 26 26"
                            fill="none"
                            class="shrink-0 text-oni-amber"
                        >
                            <path
                                d="M13 2.5 L22 7.5 L22 17.5 L13 22.5 L4 17.5 L4 7.5 Z"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linejoin="round"
                            />
                            <circle cx="13" cy="13" r="2" fill="currentColor" />
                            <path
                                d="M13 11V8M13 18v-3M9.5 15l-2.5 1.5M16.5 15l2.5 1.5M9.5 11L7 9.5M16.5 11l2.5-1.5"
                                stroke="currentColor"
                                stroke-width="1.2"
                                stroke-linecap="round"
                            />
                        </svg>
                        <span
                            class="text-[12px] font-black tracking-[0.18em] text-oni-amber uppercase"
                            >Codex</span
                        >
                    </template>

                    <div class="ml-auto flex items-center gap-1">
                        <button
                            v-if="isDesktop"
                            type="button"
                            class="flex h-7 w-7 items-center justify-center rounded text-shell-muted transition-colors hover:bg-shell-hover hover:text-shell-text"
                            :class="isPinned ? 'text-oni-amber!' : ''"
                            :title="isPinned ? 'Détacher' : 'Épingler à côté'"
                            @click="codex.togglePin()"
                        >
                            <svg
                                width="15"
                                height="15"
                                viewBox="0 0 16 16"
                                fill="none"
                            >
                                <path
                                    d="M6 2h4M8 2v5M4.5 11h7l-1.5-4h-4L4.5 11ZM8 11v3"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </button>
                        <button
                            type="button"
                            class="flex h-7 w-7 items-center justify-center rounded text-shell-muted transition-colors hover:bg-shell-hover hover:text-shell-text"
                            title="Fermer (Échap)"
                            @click="codex.close()"
                        >
                            <svg
                                width="14"
                                height="14"
                                viewBox="0 0 14 14"
                                fill="none"
                            >
                                <path
                                    d="M3.5 3.5l7 7M10.5 3.5l-7 7"
                                    stroke="currentColor"
                                    stroke-width="1.4"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </button>
                    </div>
                </div>

                <div v-if="bodyView !== 'article'" class="px-3.5 pb-3">
                    <div class="relative">
                        <svg
                            class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-shell-muted"
                            width="14"
                            height="14"
                            viewBox="0 0 14 14"
                            fill="none"
                        >
                            <circle
                                cx="6"
                                cy="6"
                                r="4"
                                stroke="currentColor"
                                stroke-width="1.3"
                            />
                            <path
                                d="M9 9l3 3"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linecap="round"
                            />
                        </svg>
                        <input
                            ref="searchInput"
                            v-model="search"
                            type="search"
                            placeholder="Rechercher dans le Codex…"
                            class="h-8 w-full rounded-md border border-shell-border bg-shell-hover/60 pr-2 pl-8 text-[12px] text-shell-text placeholder:text-shell-muted focus:border-oni-amber-dim focus:outline-none"
                        />
                    </div>
                </div>
            </header>
            <!-- endregion -->

            <!-- region Body -->
            <div class="codex-scroll relative flex-1 overflow-y-auto">
                <Transition name="codex-mode" mode="out-in">
                    <div :key="bodyView" class="px-3.5 py-3.5">
                        <!-- region Home tiles -->
                        <template v-if="bodyView === 'home'">
                            <div
                                v-if="indexLoading"
                                class="grid grid-cols-2 gap-2.5"
                            >
                                <div
                                    v-for="n in 8"
                                    :key="n"
                                    class="h-[88px] animate-pulse rounded-xl bg-[oklch(90%_0.01_250)] dark:bg-[oklch(26%_0.012_250)]"
                                />
                            </div>

                            <div
                                v-else-if="indexError"
                                class="flex flex-col items-center gap-3 py-12 text-center"
                            >
                                <p
                                    class="text-[13px] text-[oklch(45%_0.02_60)] dark:text-[oklch(70%_0.015_65)]"
                                >
                                    Impossible de charger le Codex.
                                </p>
                                <button
                                    type="button"
                                    class="rounded-md bg-oni-amber px-3 py-1.5 text-[11px] font-semibold tracking-wide text-[oklch(20%_0.05_52)] uppercase transition-opacity hover:opacity-90"
                                    @click="codex.ensureIndex()"
                                >
                                    Réessayer
                                </button>
                            </div>

                            <div v-else class="grid grid-cols-2 gap-2.5">
                                <button
                                    v-for="(tile, i) in tiles"
                                    :key="tile.category"
                                    type="button"
                                    class="codex-tile group relative flex h-[88px] flex-col justify-between overflow-hidden rounded-xl border border-black/5 p-3 text-left ring-1 ring-white/30 transition-[transform,box-shadow] duration-200 ease-[cubic-bezier(0.22,1,0.36,1)] ring-inset hover:-translate-y-0.5 hover:shadow-md dark:border-white/5 dark:ring-white/5"
                                    :style="{
                                        '--tile-hue': tile.meta.hue,
                                        animationDelay: `${i * 22}ms`,
                                    }"
                                    @click="codex.openCategory(tile.category)"
                                >
                                    <div
                                        class="flex items-start justify-between"
                                    >
                                        <span
                                            class="text-[oklch(32%_0.07_var(--tile-hue))] transition-transform duration-200 group-hover:scale-110 dark:text-[oklch(88%_0.07_var(--tile-hue))]"
                                        >
                                            <CodexCategoryIcon
                                                :category="tile.category"
                                            />
                                        </span>
                                        <span
                                            class="rounded-full bg-black/8 px-1.5 py-0.5 text-[10px] font-semibold text-[oklch(35%_0.04_var(--tile-hue))] tabular-nums dark:bg-white/10 dark:text-[oklch(85%_0.04_var(--tile-hue))]"
                                            >{{ tile.count }}</span
                                        >
                                    </div>
                                    <span
                                        class="text-[12px] leading-tight font-semibold text-[oklch(28%_0.06_var(--tile-hue))] dark:text-[oklch(92%_0.04_var(--tile-hue))]"
                                        >{{ tile.meta.label }}</span
                                    >
                                </button>
                            </div>
                        </template>
                        <!-- endregion -->

                        <!-- region Category list -->
                        <template v-else-if="bodyView === 'category'">
                            <ul class="space-y-0.5">
                                <li
                                    v-for="entry in selectedItems"
                                    :key="entry.codex_id"
                                >
                                    <button
                                        type="button"
                                        class="codex-row flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-left text-[13px] text-[oklch(28%_0.02_60)] transition-colors hover:bg-[oklch(90%_0.02_75)] dark:text-[oklch(84%_0.012_65)] dark:hover:bg-[oklch(26%_0.014_250)]"
                                        :class="
                                            activeId === entry.codex_id
                                                ? 'bg-[oklch(90%_0.03_52)] dark:bg-[oklch(26%_0.03_52)]'
                                                : ''
                                        "
                                        @click="
                                            codex.openArticle(entry.codex_id)
                                        "
                                    >
                                        <span class="truncate">{{
                                            pickLocale(
                                                entry.title,
                                                entry.codex_id,
                                            )
                                        }}</span>
                                        <svg
                                            class="ml-auto shrink-0 text-[oklch(70%_0.02_60)]"
                                            width="12"
                                            height="12"
                                            viewBox="0 0 12 12"
                                            fill="none"
                                        >
                                            <path
                                                d="M4.5 2.5L8 6l-3.5 3.5"
                                                stroke="currentColor"
                                                stroke-width="1.3"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            />
                                        </svg>
                                    </button>
                                </li>
                            </ul>
                        </template>
                        <!-- endregion -->

                        <!-- region Search results -->
                        <template v-else-if="bodyView === 'results'">
                            <div
                                v-if="resultCount === 0"
                                class="flex flex-col items-center gap-1 py-12 text-center"
                            >
                                <p
                                    class="text-[13px] font-medium text-[oklch(35%_0.02_60)] dark:text-[oklch(80%_0.012_65)]"
                                >
                                    Aucun article
                                </p>
                                <p
                                    class="text-[12px] text-[oklch(55%_0.02_60)] dark:text-[oklch(62%_0.015_65)]"
                                >
                                    pour « {{ search }} »
                                </p>
                            </div>
                            <div v-else class="space-y-5">
                                <section
                                    v-for="group in searchResults"
                                    :key="group.category"
                                >
                                    <div
                                        class="mb-1.5 flex items-center gap-2 px-1"
                                    >
                                        <span
                                            class="h-2 w-2 rounded-full"
                                            :class="TONE_DOT[group.meta.tone]"
                                        />
                                        <h3
                                            class="text-[10px] font-bold tracking-[0.14em] text-[oklch(45%_0.02_60)] uppercase dark:text-[oklch(66%_0.015_65)]"
                                        >
                                            {{ group.meta.label }}
                                        </h3>
                                        <span
                                            class="text-[10px] text-[oklch(62%_0.015_60)] tabular-nums dark:text-[oklch(55%_0.012_65)]"
                                            >{{ group.items.length }}</span
                                        >
                                    </div>
                                    <ul>
                                        <li
                                            v-for="entry in group.items"
                                            :key="entry.codex_id"
                                        >
                                            <button
                                                type="button"
                                                class="codex-row flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-left text-[13px] text-[oklch(28%_0.02_60)] transition-colors hover:bg-[oklch(90%_0.02_75)] dark:text-[oklch(84%_0.012_65)] dark:hover:bg-[oklch(26%_0.014_250)]"
                                                @click="
                                                    codex.openArticle(
                                                        entry.codex_id,
                                                    )
                                                "
                                            >
                                                <span class="truncate">{{
                                                    pickLocale(
                                                        entry.title,
                                                        entry.codex_id,
                                                    )
                                                }}</span>
                                                <svg
                                                    class="ml-auto shrink-0 text-[oklch(70%_0.02_60)]"
                                                    width="12"
                                                    height="12"
                                                    viewBox="0 0 12 12"
                                                    fill="none"
                                                >
                                                    <path
                                                        d="M4.5 2.5L8 6l-3.5 3.5"
                                                        stroke="currentColor"
                                                        stroke-width="1.3"
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                    />
                                                </svg>
                                            </button>
                                        </li>
                                    </ul>
                                </section>
                            </div>
                        </template>
                        <!-- endregion -->

                        <!-- region Article -->
                        <template v-else>
                            <div v-if="articleLoading" class="space-y-3">
                                <div
                                    class="h-6 w-2/3 animate-pulse rounded bg-[oklch(88%_0.01_250)] dark:bg-[oklch(26%_0.012_250)]"
                                />
                                <div
                                    class="h-3 w-full animate-pulse rounded bg-[oklch(90%_0.008_250)] dark:bg-[oklch(24%_0.012_250)]"
                                />
                                <div
                                    class="h-3 w-5/6 animate-pulse rounded bg-[oklch(90%_0.008_250)] dark:bg-[oklch(24%_0.012_250)]"
                                />
                                <div
                                    class="h-3 w-4/6 animate-pulse rounded bg-[oklch(90%_0.008_250)] dark:bg-[oklch(24%_0.012_250)]"
                                />
                            </div>

                            <div
                                v-else-if="articleError"
                                class="flex flex-col items-center gap-3 py-12 text-center"
                            >
                                <p
                                    class="text-[13px] text-[oklch(45%_0.02_60)] dark:text-[oklch(70%_0.015_65)]"
                                >
                                    Article introuvable.
                                </p>
                                <button
                                    type="button"
                                    class="rounded-md border border-shell-border px-3 py-1.5 text-[11px] font-semibold tracking-wide uppercase"
                                    @click="codex.goBack()"
                                >
                                    Retour
                                </button>
                            </div>

                            <article v-else-if="article" class="space-y-4">
                                <div class="space-y-2">
                                    <img
                                        v-if="article.image"
                                        :src="article.image"
                                        :alt="articleTitle"
                                        loading="lazy"
                                        class="h-28 w-28 rounded-lg border border-[oklch(86%_0.02_60)] bg-[oklch(98%_0.008_75)] object-contain p-1.5 dark:border-[oklch(30%_0.014_250)] dark:bg-[oklch(24%_0.012_250)]"
                                    />
                                    <span
                                        v-if="articleMeta"
                                        class="inline-flex items-center gap-1.5 rounded px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase"
                                        :class="TONE_CHIP[articleMeta.tone]"
                                    >
                                        <span
                                            class="h-1.5 w-1.5 rounded-full"
                                            :class="TONE_DOT[articleMeta.tone]"
                                        />
                                        {{ articleMeta.label }}
                                    </span>
                                    <h2
                                        class="text-lg font-bold tracking-tight text-[oklch(22%_0.03_60)] dark:text-[oklch(92%_0.012_65)]"
                                    >
                                        {{ articleTitle }}
                                    </h2>
                                </div>

                                <div
                                    v-for="(container, ci) in containers"
                                    :key="ci"
                                    class="space-y-2.5"
                                >
                                    <template
                                        v-for="(block, bi) in container.blocks"
                                        :key="bi"
                                    >
                                        <p
                                            v-if="isText(block)"
                                            :class="textClasses(block.style)"
                                            style="white-space: pre-line"
                                        >
                                            {{ pickLocale(block.text) }}
                                        </p>
                                        <div
                                            v-else-if="isImage(block)"
                                            class="flex items-center gap-2 rounded-md border border-dashed border-[oklch(80%_0.02_60)] bg-[oklch(93%_0.012_75)] px-3 py-2 text-[11px] text-[oklch(55%_0.02_60)] dark:border-[oklch(34%_0.015_250)] dark:bg-[oklch(24%_0.014_250)] dark:text-[oklch(62%_0.015_65)]"
                                        >
                                            <svg
                                                width="16"
                                                height="16"
                                                viewBox="0 0 18 18"
                                                fill="none"
                                            >
                                                <rect
                                                    x="2.5"
                                                    y="3.5"
                                                    width="13"
                                                    height="11"
                                                    rx="1.5"
                                                    stroke="currentColor"
                                                    stroke-width="1.3"
                                                />
                                                <circle
                                                    cx="6.5"
                                                    cy="7"
                                                    r="1.2"
                                                    stroke="currentColor"
                                                    stroke-width="1.1"
                                                />
                                                <path
                                                    d="M3.5 13l3.5-3.5 2.5 2 2.5-3 2.5 2.5"
                                                    stroke="currentColor"
                                                    stroke-width="1.2"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                            <span class="truncate">{{
                                                block.sprite ?? 'image'
                                            }}</span>
                                        </div>
                                        <hr
                                            v-else
                                            class="border-t border-[oklch(86%_0.015_60)] dark:border-[oklch(30%_0.014_250)]"
                                        />
                                    </template>
                                </div>

                                <div
                                    v-if="article.links.length"
                                    class="space-y-2 border-t border-[oklch(88%_0.012_60)] pt-3 dark:border-[oklch(28%_0.014_250)]"
                                >
                                    <h3
                                        class="text-[10px] font-bold tracking-[0.14em] text-[oklch(48%_0.02_60)] uppercase dark:text-[oklch(64%_0.015_65)]"
                                    >
                                        Lié à
                                    </h3>
                                    <div class="flex flex-wrap gap-1.5">
                                        <component
                                            :is="link.url ? Link : 'span'"
                                            v-for="link in article.links"
                                            :key="link.type + link.key"
                                            :href="link.url ?? undefined"
                                            :title="
                                                LINK_LABEL[link.type] ??
                                                link.type
                                            "
                                            class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-medium transition-transform"
                                            :class="[
                                                TONE_CHIP[
                                                    LINK_TONE[link.type] ??
                                                        'neutral'
                                                ],
                                                link.url
                                                    ? 'cursor-pointer hover:-translate-y-px'
                                                    : 'cursor-default opacity-75',
                                            ]"
                                            @click="
                                                link.url ? onNavigate() : null
                                            "
                                        >
                                            <span
                                                class="h-1.5 w-1.5 rounded-full"
                                                :class="
                                                    TONE_DOT[
                                                        LINK_TONE[link.type] ??
                                                            'neutral'
                                                    ]
                                                "
                                            />
                                            {{ link.label }}
                                        </component>
                                    </div>
                                </div>
                            </article>
                        </template>
                        <!-- endregion -->
                    </div>
                </Transition>
            </div>
            <!-- endregion -->
        </aside>
        <!-- endregion -->
    </Teleport>
</template>

<style scoped>
.codex-scroll {
    scrollbar-width: thin;
}

/* Per-category tinted tile, theme-aware via the --tile-hue custom property */
.codex-tile {
    background-image: linear-gradient(
        140deg,
        oklch(95% 0.05 var(--tile-hue)),
        oklch(89% 0.085 var(--tile-hue))
    );
    animation: codex-tile-in 280ms cubic-bezier(0.22, 1, 0.36, 1) both;
}
@media (prefers-color-scheme: dark) {
    .codex-tile {
        background-image: linear-gradient(
            140deg,
            oklch(31% 0.06 var(--tile-hue)),
            oklch(24% 0.05 var(--tile-hue))
        );
    }
}
@keyframes codex-tile-in {
    from {
        opacity: 0;
        transform: translateY(8px) scale(0.98);
    }
}

/* Languette slide/fade */
.codex-tab-enter-active,
.codex-tab-leave-active {
    transition:
        transform 260ms cubic-bezier(0.22, 1, 0.36, 1),
        opacity 200ms ease-out;
}
.codex-tab-enter-from,
.codex-tab-leave-to {
    transform: translate(60%, -50%);
    opacity: 0;
}

/* Scrim fade */
.codex-scrim-enter-active,
.codex-scrim-leave-active {
    transition: opacity 220ms ease-out;
}
.codex-scrim-enter-from,
.codex-scrim-leave-to {
    opacity: 0;
}

/* View cross-fade with a small horizontal drift */
.codex-mode-enter-active,
.codex-mode-leave-active {
    transition:
        transform 200ms cubic-bezier(0.22, 1, 0.36, 1),
        opacity 160ms ease-out;
}
.codex-mode-enter-from {
    transform: translateX(10px);
    opacity: 0;
}
.codex-mode-leave-to {
    transform: translateX(-10px);
    opacity: 0;
}

/* Subtle row reveal */
.codex-row {
    animation: codex-row-in 220ms cubic-bezier(0.22, 1, 0.36, 1) both;
}
@keyframes codex-row-in {
    from {
        opacity: 0;
        transform: translateX(6px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .codex-tile,
    .codex-row {
        animation: none;
    }
}
</style>
