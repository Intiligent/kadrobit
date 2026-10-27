import { defineConfig } from 'vite';
import path from 'path';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import inertia from '@inertiajs/vite';
import i18n from 'laravel-vue-i18n/vite';
import AutoImport from 'unplugin-auto-import/vite';
import Components from 'unplugin-vue-components/vite';
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/style/tailwind.css', 'resources/style/app.scss', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Headings of the home page (font-serif). Latin only: Russian headings fall back to Georgia.
                bunny('Instrument Serif', {
                    weights: [400],
                    styles: ['normal', 'italic'],
                }),
            ],
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        inertia({
            ssr: false,
        }),
        // Compiles lang/{locale}/*.php into lang/php_{locale}.json for the frontend.
        i18n(),
        // Element Plus components are imported on demand with their SCSS sources,
        // so the styles compile with the theme variables below.
        AutoImport({
            dts: false,
            resolvers: [ElementPlusResolver({ importStyle: 'sass' })],
        }),
        Components({
            dts: false,
            resolvers: [ElementPlusResolver({ importStyle: 'sass' })],
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // Element Plus theme variables for every SCSS file, including Element Plus sources.
                additionalData: `@use "${path.resolve(import.meta.dirname, 'resources/style/theme/variables.scss')}" as *;\n`,
            },
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, 'resources/js'),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**', '**/.claude/**', '**/lang/php_*.json'],
        },
    },
});
