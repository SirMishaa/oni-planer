import { computed, ref } from 'vue';
import type {
    CodexArticleData,
    CodexListItemData,
} from '@/types/App/Data/data';

// region Types
export type CodexTone = 'solid' | 'liquid' | 'gas' | 'special' | 'neutral';

type CategoryMeta = {
    label: string;
    tone: CodexTone;
    hue: number;
    order: number;
};

const CATEGORY_META: Record<string, CategoryMeta> = {
    // Gameplay
    Buildings: { label: 'Bâtiments', tone: 'solid', hue: 52, order: 1 },
    ElementTypes: { label: 'Éléments', tone: 'liquid', hue: 222, order: 2 },
    Creatures: { label: 'Créatures', tone: 'special', hue: 330, order: 3 },
    Plants: { label: 'Plantes', tone: 'gas', hue: 142, order: 4 },
    Geysers: { label: 'Geysers', tone: 'liquid', hue: 200, order: 5 },
    Systems: { label: 'Systèmes', tone: 'solid', hue: 275, order: 6 },
    IndustrialIngredients: {
        label: 'Ingrédients industriels',
        tone: 'solid',
        hue: 40,
        order: 7,
    },
    TechComponents: {
        label: 'Composants techniques',
        tone: 'neutral',
        hue: 250,
        order: 8,
    },
    Medicines: { label: 'Médecine', tone: 'special', hue: 165, order: 9 },
    DuplicantsCategory: {
        label: 'Duplicants',
        tone: 'special',
        hue: 25,
        order: 10,
    },
    Ornaments: { label: 'Ornements', tone: 'neutral', hue: 95, order: 11 },
    StoryTraits: {
        label: 'Traits scénarisés',
        tone: 'neutral',
        hue: 310,
        order: 12,
    },
    // Lore
    MyLog: { label: 'Journal de bord', tone: 'neutral', hue: 70, order: 20 },
    Emails: { label: 'E-mails', tone: 'neutral', hue: 235, order: 21 },
    Journals: { label: 'Journaux', tone: 'neutral', hue: 85, order: 22 },
    ResearchNotes: {
        label: 'Notes de recherche',
        tone: 'neutral',
        hue: 180,
        order: 23,
    },
    Investigations: { label: 'Enquêtes', tone: 'neutral', hue: 300, order: 24 },
    Notices: { label: 'Avis', tone: 'neutral', hue: 30, order: 25 },
    Media: { label: 'Médias', tone: 'neutral', hue: 130, order: 26 },
    Root: { label: 'Accueil', tone: 'neutral', hue: 110, order: 27 },
};

export function categoryMeta(category: string): CategoryMeta {
    return (
        CATEGORY_META[category] ?? {
            label: category,
            tone: 'neutral',
            hue: 250,
            order: 99,
        }
    );
}

export type CodexTile = { category: string; meta: CategoryMeta; count: number };
export type CodexGroup = {
    category: string;
    meta: CategoryMeta;
    items: CodexListItemData[];
};
export type CodexMode = 'home' | 'category' | 'article';
// endregion

// region Locale
const LOCALE_ORDER = ['fr', 'en'];

export function pickLocale(
    map: Record<string, string> | null | undefined,
    fallback = '',
): string {
    if (!map) return fallback;
    for (const locale of LOCALE_ORDER) {
        const value = map[locale];
        if (value) return value;
    }
    const first = Object.values(map)[0];
    return first ?? fallback;
}

function titleOf(entry: CodexListItemData): string {
    return pickLocale(entry.title, entry.codex_id);
}
// endregion

// region Singleton state
const STORAGE = {
    pinned: 'oni-codex-pinned',
    open: 'oni-codex-open',
    width: 'oni-codex-width',
};

const MIN_WIDTH = 320;
const MAX_WIDTH = 560;

const isOpen = ref(false);
const isPinned = ref(false);
const isDesktop = ref(true);
const width = ref(420);

const mode = ref<CodexMode>('home');
const selectedCategory = ref<string | null>(null);
const search = ref('');

const index = ref<CodexListItemData[] | null>(null);
const indexLoading = ref(false);
const indexError = ref(false);

const article = ref<CodexArticleData | null>(null);
const activeId = ref<string | null>(null);
const articleLoading = ref(false);
const articleError = ref(false);

let hydrated = false;

function hydrate(): void {
    if (hydrated || typeof window === 'undefined') return;
    hydrated = true;

    isPinned.value = localStorage.getItem(STORAGE.pinned) === 'true';
    // Only a docked panel survives a reload; an overlay reopens on demand.
    isOpen.value =
        isPinned.value && localStorage.getItem(STORAGE.open) === 'true';

    const storedWidth = Number(localStorage.getItem(STORAGE.width));
    if (storedWidth >= MIN_WIDTH && storedWidth <= MAX_WIDTH) {
        width.value = storedWidth;
    }

    const query = window.matchMedia('(min-width: 768px)');
    isDesktop.value = query.matches;
    query.addEventListener('change', (event) => {
        isDesktop.value = event.matches;
    });
}
// endregion

// region Fetching
async function ensureIndex(): Promise<void> {
    if (index.value || indexLoading.value) return;
    indexLoading.value = true;
    indexError.value = false;
    try {
        const response = await fetch('/codex', {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(String(response.status));
        index.value = (await response.json()) as CodexListItemData[];
    } catch {
        indexError.value = true;
    } finally {
        indexLoading.value = false;
    }
}

async function openArticle(codexId: string): Promise<void> {
    if (!isOpen.value) open();
    mode.value = 'article';
    activeId.value = codexId;
    article.value = null;
    articleLoading.value = true;
    articleError.value = false;
    try {
        const response = await fetch(`/codex/${encodeURIComponent(codexId)}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(String(response.status));
        article.value = (await response.json()) as CodexArticleData;
    } catch {
        articleError.value = true;
    } finally {
        articleLoading.value = false;
    }
}
// endregion

// region Commands
function open(): void {
    isOpen.value = true;
    if (isPinned.value) localStorage.setItem(STORAGE.open, 'true');
    void ensureIndex();
}

function close(): void {
    isOpen.value = false;
    localStorage.setItem(STORAGE.open, 'false');
}

function toggle(): void {
    isOpen.value ? close() : open();
}

function togglePin(): void {
    isPinned.value = !isPinned.value;
    localStorage.setItem(STORAGE.pinned, String(isPinned.value));
    localStorage.setItem(STORAGE.open, String(isOpen.value));
}

function home(): void {
    mode.value = 'home';
    selectedCategory.value = null;
    article.value = null;
    articleError.value = false;
}

function openCategory(category: string): void {
    mode.value = 'category';
    selectedCategory.value = category;
    search.value = '';
}

function goBack(): void {
    if (mode.value === 'article') {
        mode.value = selectedCategory.value ? 'category' : 'home';
        article.value = null;
        articleError.value = false;
        return;
    }
    home();
}

function setWidth(next: number): void {
    width.value = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, Math.round(next)));
    localStorage.setItem(STORAGE.width, String(width.value));
}
// endregion

// region Derived
const searching = computed(() => search.value.trim() !== '');

const tiles = computed<CodexTile[]>(() => {
    if (!index.value) return [];

    const counts = new Map<string, number>();
    for (const entry of index.value) {
        counts.set(entry.category, (counts.get(entry.category) ?? 0) + 1);
    }

    return [...counts.entries()]
        .map(([category, count]) => ({
            category,
            meta: categoryMeta(category),
            count,
        }))
        .sort(
            (a, b) =>
                a.meta.order - b.meta.order ||
                a.meta.label.localeCompare(b.meta.label),
        );
});

const selectedItems = computed<CodexListItemData[]>(() => {
    if (!index.value || !selectedCategory.value) return [];
    return index.value
        .filter((entry) => entry.category === selectedCategory.value)
        .sort((a, b) => titleOf(a).localeCompare(titleOf(b)));
});

const searchResults = computed<CodexGroup[]>(() => {
    if (!index.value) return [];

    const query = search.value.trim().toLowerCase();
    if (!query) return [];

    const matches = index.value.filter(
        (entry) =>
            entry.codex_id.toLowerCase().includes(query) ||
            titleOf(entry).toLowerCase().includes(query),
    );

    const byCategory = new Map<string, CodexListItemData[]>();
    for (const entry of matches) {
        const bucket = byCategory.get(entry.category) ?? [];
        bucket.push(entry);
        byCategory.set(entry.category, bucket);
    }

    return [...byCategory.entries()]
        .map(([category, items]) => ({
            category,
            meta: categoryMeta(category),
            items: items.sort((a, b) => titleOf(a).localeCompare(titleOf(b))),
        }))
        .sort((a, b) => a.meta.order - b.meta.order);
});

const resultCount = computed(() =>
    searchResults.value.reduce((total, group) => total + group.items.length, 0),
);

const dockActive = computed(
    () => isPinned.value && isOpen.value && isDesktop.value,
);
// endregion

export function useCodex() {
    hydrate();

    return {
        // state
        isOpen,
        isPinned,
        isDesktop,
        width,
        mode,
        selectedCategory,
        search,
        index,
        indexLoading,
        indexError,
        article,
        activeId,
        articleLoading,
        articleError,
        // derived
        searching,
        tiles,
        selectedItems,
        searchResults,
        resultCount,
        dockActive,
        // commands
        open,
        close,
        toggle,
        togglePin,
        home,
        openCategory,
        goBack,
        openArticle,
        ensureIndex,
        setWidth,
    };
}
