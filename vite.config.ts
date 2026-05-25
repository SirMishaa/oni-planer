import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import {defineConfig} from 'vite-plus';

export default defineConfig({
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        useTabs: false,
        semi: true,
        singleQuote: true,
        overrides: [
            {
                files: ["**/*.yml"],
                options: {
                    tabWidth: 2,
                },
            },
        ],
        sortTailwindcss: {
            functions: ["clsx", "cn"],
            stylesheet: "resources/css/app.css",
        },
        sortImports: {
            groups: ["builtin", "external", "internal", "parent", "sibling", "index"],
            newlinesBetween: false,
        },
        ignorePatterns: ["resources/views/mail/*"],
    },
    lint: {
        plugins: ["vue", "typescript"],
        categories: {
            correctness: "error",
        },
        rules: {
            "vue/define-props-declaration": "error",
            "vue/define-emits-declaration": "error",
            "vue/require-typed-ref": "error",
            "typescript/no-explicit-any": "error",
            "typescript/consistent-type-imports": "error",
        },
        options: {
            typeAware: true,
            typeCheck: true,
        },
    },
    staged: {
        "*": "vp check --fix",
    },
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.ts"],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
});
