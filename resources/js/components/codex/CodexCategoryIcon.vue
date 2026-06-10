<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ category: string }>();

const KEY_BY_CATEGORY: Record<string, string> = {
    Buildings: 'building',
    ElementTypes: 'element',
    Creatures: 'creature',
    Plants: 'plant',
    Geysers: 'geyser',
    Systems: 'system',
    IndustrialIngredients: 'flask',
    TechComponents: 'chip',
    Medicines: 'pill',
    DuplicantsCategory: 'dupe',
    Ornaments: 'ornament',
    StoryTraits: 'mask',
    MyLog: 'book',
    Journals: 'book',
    Emails: 'mail',
    ResearchNotes: 'search',
    Investigations: 'search',
    Notices: 'notice',
    Media: 'media',
    Root: 'home',
};

const key = computed(() => KEY_BY_CATEGORY[props.category] ?? 'doc');
</script>

<template>
    <svg
        width="22"
        height="22"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <template v-if="key === 'building'">
            <rect x="3" y="10" width="7" height="10" rx="1" />
            <rect x="13" y="4" width="8" height="16" rx="1" />
            <path d="M5.5 10V8a1.5 1.5 0 0 1 3 0v2" />
        </template>
        <template v-else-if="key === 'element'">
            <circle cx="12" cy="5" r="2" />
            <circle cx="5" cy="17" r="2" />
            <circle cx="19" cy="17" r="2" />
            <path d="M12 7 6.5 15M12 7l5.5 8M7 17h10" />
        </template>
        <template v-else-if="key === 'creature'">
            <ellipse cx="12" cy="14" rx="6.5" ry="4.5" />
            <circle cx="9.6" cy="13" r="1" fill="currentColor" stroke="none" />
            <circle cx="14.4" cy="13" r="1" fill="currentColor" stroke="none" />
            <path d="M8 9.5C7.3 7.5 8 6 9.2 5.5M16 9.5c.7-2 0-3.5-1.2-4" />
        </template>
        <template v-else-if="key === 'plant'">
            <path d="M12 20v-9" />
            <path d="M12 14c0-4 3.2-6.5 7-6.5 0 4-3.2 6.5-7 6.5Z" />
            <path d="M12 17c0-3.3-2.7-5.5-6-5.5 0 3.3 2.7 5.5 6 5.5Z" />
        </template>
        <template v-else-if="key === 'geyser'">
            <path d="M12 20v-8M9 20h6" />
            <path d="M12 12c0-2.6 2-4.6 4.6-4.6M12 12c0-2.6-2-4.6-4.6-4.6" />
            <path d="M10.5 6Q12 4 13.5 6M11 3.2Q12 2 13 3.2" />
        </template>
        <template v-else-if="key === 'system'">
            <circle cx="12" cy="12" r="3" />
            <path
                d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"
            />
        </template>
        <template v-else-if="key === 'flask'">
            <path
                d="M9 3h6M10 3v6L5 18a2 2 0 0 0 1.8 3h10.4A2 2 0 0 0 19 18l-5-9V3"
            />
            <path d="M7.5 14h9" />
        </template>
        <template v-else-if="key === 'chip'">
            <rect x="7" y="7" width="10" height="10" rx="1.5" />
            <path
                d="M10 3v3M14 3v3M10 18v3M14 18v3M3 10h3M3 14h3M18 10h3M18 14h3"
            />
        </template>
        <template v-else-if="key === 'pill'">
            <rect
                x="3.5"
                y="9"
                width="17"
                height="6"
                rx="3"
                transform="rotate(-45 12 12)"
            />
            <path d="M9.5 9.5l5 5" />
        </template>
        <template v-else-if="key === 'dupe'">
            <circle cx="12" cy="7.5" r="3" />
            <path d="M5.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6" />
        </template>
        <template v-else-if="key === 'ornament'">
            <path
                d="M12 3l2.5 5.3 5.5.7-4 4 1 5.6-5-2.8-5 2.8 1-5.6-4-4 5.5-.7Z"
            />
        </template>
        <template v-else-if="key === 'mask'">
            <path d="M5 5c4 1.5 10 1.5 14 0 1 5-1 11-7 14C6 16 4 10 5 5Z" />
            <path d="M9.5 10.5h.01M14.5 10.5h.01M10 14.5c1.2 1 2.8 1 4 0" />
        </template>
        <template v-else-if="key === 'book'">
            <path d="M5 4.5h11a2 2 0 0 1 2 2v13H7a2 2 0 0 0-2 2Z" />
            <path d="M5 4.5v15M9 8.5h5M9 11.5h5" />
        </template>
        <template v-else-if="key === 'mail'">
            <rect x="3.5" y="6" width="17" height="12" rx="2" />
            <path d="M4 7.5l8 5.5 8-5.5" />
        </template>
        <template v-else-if="key === 'search'">
            <circle cx="11" cy="11" r="6" />
            <path d="M15.5 15.5L20 20" />
        </template>
        <template v-else-if="key === 'notice'">
            <path d="M12 4l8 14H4Z" />
            <path d="M12 10v4M12 16.5h.01" />
        </template>
        <template v-else-if="key === 'media'">
            <rect x="3.5" y="5" width="17" height="14" rx="2" />
            <circle cx="8.5" cy="10" r="1.5" />
            <path d="M4 17l5-5 3.5 3 3-3.5L20 16" />
        </template>
        <template v-else-if="key === 'home'">
            <path d="M4 11l8-6.5L20 11" />
            <path d="M6 9.5V20h12V9.5" />
        </template>
        <template v-else>
            <path
                d="M7 3.5h7l4 4V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z"
            />
            <path d="M13.5 3.5V8H18M9 12.5h6M9 15.5h6" />
        </template>
    </svg>
</template>
