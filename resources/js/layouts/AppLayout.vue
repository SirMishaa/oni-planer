<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

const STORAGE_KEY = 'oni-sidebar-expanded';
const expanded = ref(true);

onMounted(() => {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored !== null) expanded.value = stored === 'true';
});

function toggle() {
    expanded.value = !expanded.value;
    localStorage.setItem(STORAGE_KEY, String(expanded.value));
}

const page = usePage();

type NavItem = {
    key: string;
    label: string;
    href: string;
    available: boolean;
};

const navItems: NavItem[] = [
    { key: 'elements', label: 'Éléments', href: '/', available: true },
    {
        key: 'buildings',
        label: 'Bâtiments',
        href: '/buildings',
        available: false,
    },
    { key: 'geysers', label: 'Geysers', href: '/geysers', available: false },
    {
        key: 'critters',
        label: 'Créatures',
        href: '/critters',
        available: false,
    },
    { key: 'plants', label: 'Plantes', href: '/plants', available: false },
    { key: 'recipes', label: 'Recettes', href: '/recipes', available: false },
];

function isActive(item: NavItem): boolean {
    const url = page.url;
    if (item.href === '/') return url === '/' || url.startsWith('/elements');
    return url.startsWith(item.href);
}
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <!-- region Sidebar -->
        <aside
            class="relative hidden flex-col border-r border-shell-border bg-shell md:flex"
            :class="expanded ? 'w-55' : 'w-14'"
            style="
                transition: width 180ms cubic-bezier(0.4, 0, 0.2, 1);
                background-image: radial-gradient(circle, rgba(255,255,255,0.055) 1px, transparent 1px);
                background-size: 20px 20px;
            "
        >
            <!-- Ambient glow from the logo area -->
            <div
                class="pointer-events-none absolute inset-0"
                style="
                    background: radial-gradient(
                        ellipse 140% 30% at 50% 0%,
                        oklch(18% 0.05 50 / 0.45),
                        transparent 65%
                    );
                "
            />

            <!-- region Wordmark -->
            <div
                class="relative flex h-14 items-center gap-3 border-b border-shell-border px-3.5"
            >
                <!-- Hexagon circuit icon -->
                <div class="flex h-7 w-7 shrink-0 items-center justify-center">
                    <svg
                        width="26"
                        height="26"
                        viewBox="0 0 26 26"
                        fill="none"
                        class="text-oni-amber"
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
                </div>

                <div
                    class="flex min-w-0 flex-col overflow-hidden"
                    :class="expanded ? 'opacity-100' : 'w-0 opacity-0'"
                    style="
                        transition:
                            opacity 120ms ease-out,
                            width 180ms cubic-bezier(0.4, 0, 0.2, 1);
                    "
                >
                    <span
                        class="text-[13px] font-black tracking-[0.14em] whitespace-nowrap text-oni-amber"
                        >ONI</span
                    >
                    <span
                        class="text-[10px] font-light tracking-widest whitespace-nowrap text-shell-muted"
                        >Planner</span
                    >
                </div>

                <!-- Toggle chevron -->
                <button
                    class="absolute top-1/2 right-2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded text-shell-muted transition-colors hover:bg-shell-hover hover:text-shell-text focus-visible:outline-none"
                    :class="!expanded ? 'right-1.5' : ''"
                    :title="expanded ? 'Réduire' : 'Développer'"
                    @click="toggle"
                >
                    <svg
                        width="12"
                        height="12"
                        viewBox="0 0 12 12"
                        fill="none"
                        :style="expanded ? '' : 'transform: rotate(180deg)'"
                    >
                        <path
                            d="M7.5 2.5L4 6l3.5 3.5"
                            stroke="currentColor"
                            stroke-width="1.4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </button>
            </div>
            <!-- endregion -->

            <!-- region Nav -->
            <nav class="relative flex-1 space-y-0.5 px-2 pt-3">
                <template v-for="item in navItems" :key="item.key">
                    <!-- Available item -->
                    <Link
                        v-if="item.available"
                        :href="item.href"
                        class="group flex h-9 items-center gap-3 rounded-md px-2.5 text-shell-text transition-colors hover:bg-shell-hover hover:text-shell-text"
                        :class="
                            isActive(item)
                                ? 'bg-shell-active text-oni-amber!'
                                : ''
                        "
                        :title="!expanded ? item.label : undefined"
                    >
                        <span
                            class="flex h-5 w-5 shrink-0 items-center justify-center"
                        >
                            <!-- Elements icon: atomic lattice -->
                            <svg
                                v-if="item.key === 'elements'"
                                width="18"
                                height="18"
                                viewBox="0 0 18 18"
                                fill="none"
                            >
                                <circle
                                    cx="9"
                                    cy="3.5"
                                    r="1.8"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                />
                                <circle
                                    cx="3.5"
                                    cy="13"
                                    r="1.8"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                />
                                <circle
                                    cx="14.5"
                                    cy="13"
                                    r="1.8"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                />
                                <path
                                    d="M9 5.3 L4.8 11.2M9 5.3 L13.2 11.2M5.3 13 L13 13"
                                    stroke="currentColor"
                                    stroke-width="1.2"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </span>

                        <span
                            class="overflow-hidden text-[11px] font-medium tracking-widest whitespace-nowrap uppercase"
                            :class="
                                expanded
                                    ? 'max-w-full opacity-100'
                                    : 'max-w-0 opacity-0'
                            "
                            style="
                                transition:
                                    max-width 180ms cubic-bezier(0.4, 0, 0.2, 1),
                                    opacity 100ms ease-out;
                            "
                        >
                            {{ item.label }}
                        </span>
                    </Link>

                    <!-- Stub item (not yet available) -->
                    <div
                        v-else
                        class="flex h-9 items-center gap-3 rounded-md px-2.5 opacity-40"
                        :title="!expanded ? item.label : undefined"
                    >
                        <span
                            class="flex h-5 w-5 shrink-0 items-center justify-center text-shell-muted"
                        >
                            <!-- Buildings -->
                            <svg
                                v-if="item.key === 'buildings'"
                                width="18"
                                height="18"
                                viewBox="0 0 18 18"
                                fill="none"
                            >
                                <rect
                                    x="2"
                                    y="8"
                                    width="6"
                                    height="8"
                                    rx="1"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                />
                                <rect
                                    x="10"
                                    y="4"
                                    width="6"
                                    height="12"
                                    rx="1"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                />
                                <path
                                    d="M4 8V6.5a2 2 0 0 1 4 0V8"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                />
                            </svg>
                            <!-- Geysers -->
                            <svg
                                v-else-if="item.key === 'geysers'"
                                width="18"
                                height="18"
                                viewBox="0 0 18 18"
                                fill="none"
                            >
                                <path
                                    d="M9 15V9"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M6.5 15h5"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M9 9c0-2 1.5-3.5 3.5-3.5"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M9 9c0-2-1.5-3.5-3.5-3.5"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M8 4.5 Q9 3 10 4.5"
                                    stroke="currentColor"
                                    stroke-width="1.2"
                                    stroke-linecap="round"
                                    fill="none"
                                />
                                <path
                                    d="M8.5 2.5 Q9 1.5 9.5 2.5"
                                    stroke="currentColor"
                                    stroke-width="1.2"
                                    stroke-linecap="round"
                                    fill="none"
                                />
                            </svg>
                            <!-- Critters -->
                            <svg
                                v-else-if="item.key === 'critters'"
                                width="18"
                                height="18"
                                viewBox="0 0 18 18"
                                fill="none"
                            >
                                <ellipse
                                    cx="9"
                                    cy="11"
                                    rx="5"
                                    ry="3.5"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                />
                                <circle
                                    cx="7.2"
                                    cy="10.5"
                                    r="0.9"
                                    fill="currentColor"
                                />
                                <circle
                                    cx="10.8"
                                    cy="10.5"
                                    r="0.9"
                                    fill="currentColor"
                                />
                                <path
                                    d="M4 11c-1.5.5-2.5 1.5-2 2.5"
                                    stroke="currentColor"
                                    stroke-width="1.2"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M14 11c1.5.5 2.5 1.5 2 2.5"
                                    stroke="currentColor"
                                    stroke-width="1.2"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M6 7.5C5.5 6 6 4.5 7 4"
                                    stroke="currentColor"
                                    stroke-width="1.2"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M12 7.5C12.5 6 12 4.5 11 4"
                                    stroke="currentColor"
                                    stroke-width="1.2"
                                    stroke-linecap="round"
                                />
                            </svg>
                            <!-- Plants -->
                            <svg
                                v-else-if="item.key === 'plants'"
                                width="18"
                                height="18"
                                viewBox="0 0 18 18"
                                fill="none"
                            >
                                <path
                                    d="M9 15V8"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                />
                                <path
                                    d="M9 10.5C9 7.5 11.5 5 14.5 5 14.5 8 12 10.5 9 10.5Z"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linejoin="round"
                                    fill="none"
                                />
                                <path
                                    d="M9 13C9 10.5 7 8.5 4 8.5 4 11 6 13 9 13Z"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linejoin="round"
                                    fill="none"
                                />
                            </svg>
                            <!-- Recipes -->
                            <svg
                                v-else-if="item.key === 'recipes'"
                                width="18"
                                height="18"
                                viewBox="0 0 18 18"
                                fill="none"
                            >
                                <path
                                    d="M6.5 3h5l1.5 4H5L6.5 3Z"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linejoin="round"
                                />
                                <path
                                    d="M5 7v7a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V7"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                />
                                <path
                                    d="M7.5 11.5h3"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </span>

                        <div
                            class="flex min-w-0 flex-1 items-center gap-2 overflow-hidden"
                            :class="
                                expanded ? 'opacity-100' : 'max-w-0 opacity-0'
                            "
                            style="
                                transition:
                                    max-width 180ms cubic-bezier(0.4, 0, 0.2, 1),
                                    opacity 100ms ease-out;
                            "
                        >
                            <span
                                class="text-[11px] font-medium tracking-widest whitespace-nowrap text-shell-muted uppercase"
                            >
                                {{ item.label }}
                            </span>
                            <span
                                class="rounded px-1 py-px text-[8px] font-semibold tracking-[0.08em] whitespace-nowrap uppercase"
                                style="
                                    background: oklch(22% 0.022 148);
                                    color: oklch(55% 0.022 148);
                                "
                            >
                                bientôt
                            </span>
                        </div>
                    </div>
                </template>
            </nav>
            <!-- endregion -->

            <!-- region Version -->
            <div
                class="relative border-t border-shell-border px-3.5 py-3"
                :class="expanded ? 'opacity-100' : 'opacity-0'"
                style="transition: opacity 100ms ease-out"
            >
                <p
                    class="text-[9px] font-medium tracking-[0.12em] text-shell-muted uppercase opacity-40"
                >
                    ONI&nbsp;·&nbsp;v0.1
                </p>
            </div>
            <!-- endregion -->
        </aside>
        <!-- endregion -->

        <!-- region Content -->
        <div class="flex flex-1 flex-col overflow-hidden">
            <div
                class="content-grid flex-1 overflow-y-auto bg-[oklch(94%_0.015_75)] text-[oklch(18%_0.025_60)] dark:bg-[oklch(22%_0.016_250)] dark:text-[oklch(90%_0.012_65)]"
            >
                <slot />
            </div>
        </div>
        <!-- endregion -->

        <!-- region Mobile nav -->
        <nav
            class="fixed inset-x-0 bottom-0 z-20 flex border-t border-shell-border bg-shell md:hidden"
        >
            <template v-for="item in navItems.slice(0, 5)" :key="item.key">
                <Link
                    v-if="item.available"
                    :href="item.href"
                    class="flex flex-1 flex-col items-center gap-1 py-2.5 text-shell-text transition-colors"
                    :class="isActive(item) ? 'text-oni-amber!' : ''"
                >
                    <span class="flex h-5 w-5 items-center justify-center">
                        <svg
                            v-if="item.key === 'elements'"
                            width="18"
                            height="18"
                            viewBox="0 0 18 18"
                            fill="none"
                        >
                            <circle
                                cx="9"
                                cy="3.5"
                                r="1.8"
                                stroke="currentColor"
                                stroke-width="1.3"
                            />
                            <circle
                                cx="3.5"
                                cy="13"
                                r="1.8"
                                stroke="currentColor"
                                stroke-width="1.3"
                            />
                            <circle
                                cx="14.5"
                                cy="13"
                                r="1.8"
                                stroke="currentColor"
                                stroke-width="1.3"
                            />
                            <path
                                d="M9 5.3 L4.8 11.2M9 5.3 L13.2 11.2M5.3 13 L13 13"
                                stroke="currentColor"
                                stroke-width="1.2"
                                stroke-linecap="round"
                            />
                        </svg>
                    </span>
                    <span
                        class="text-[9px] font-medium tracking-widest uppercase"
                        >{{ item.label }}</span
                    >
                </Link>
                <button
                    v-else
                    class="flex flex-1 flex-col items-center gap-1 py-2.5 opacity-25"
                    disabled
                >
                    <span
                        class="flex h-5 w-5 items-center justify-center text-shell-muted"
                    >
                        <svg
                            v-if="item.key === 'buildings'"
                            width="18"
                            height="18"
                            viewBox="0 0 18 18"
                            fill="none"
                        >
                            <rect
                                x="2"
                                y="8"
                                width="6"
                                height="8"
                                rx="1"
                                stroke="currentColor"
                                stroke-width="1.3"
                            />
                            <rect
                                x="10"
                                y="4"
                                width="6"
                                height="12"
                                rx="1"
                                stroke="currentColor"
                                stroke-width="1.3"
                            />
                            <path
                                d="M4 8V6.5a2 2 0 0 1 4 0V8"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linecap="round"
                            />
                        </svg>
                        <svg
                            v-else-if="item.key === 'geysers'"
                            width="18"
                            height="18"
                            viewBox="0 0 18 18"
                            fill="none"
                        >
                            <path
                                d="M9 15V9"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linecap="round"
                            />
                            <path
                                d="M6.5 15h5"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linecap="round"
                            />
                            <path
                                d="M9 9c0-2 1.5-3.5 3.5-3.5"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linecap="round"
                            />
                            <path
                                d="M9 9c0-2-1.5-3.5-3.5-3.5"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linecap="round"
                            />
                        </svg>
                        <svg
                            v-else-if="item.key === 'critters'"
                            width="18"
                            height="18"
                            viewBox="0 0 18 18"
                            fill="none"
                        >
                            <ellipse
                                cx="9"
                                cy="11"
                                rx="5"
                                ry="3.5"
                                stroke="currentColor"
                                stroke-width="1.3"
                            />
                            <circle
                                cx="7.2"
                                cy="10.5"
                                r="0.9"
                                fill="currentColor"
                            />
                            <circle
                                cx="10.8"
                                cy="10.5"
                                r="0.9"
                                fill="currentColor"
                            />
                        </svg>
                        <svg
                            v-else-if="item.key === 'plants'"
                            width="18"
                            height="18"
                            viewBox="0 0 18 18"
                            fill="none"
                        >
                            <path
                                d="M9 15V8"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linecap="round"
                            />
                            <path
                                d="M9 10.5C9 7.5 11.5 5 14.5 5 14.5 8 12 10.5 9 10.5Z"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linejoin="round"
                                fill="none"
                            />
                        </svg>
                    </span>
                    <span
                        class="text-[9px] font-medium tracking-widest text-shell-muted uppercase"
                        >{{ item.label }}</span
                    >
                </button>
            </template>
        </nav>
        <!-- endregion -->
    </div>
</template>
