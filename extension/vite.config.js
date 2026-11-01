import path from 'path';
import vue from '@vitejs/plugin-vue';
import AutoImport from 'unplugin-auto-import/vite';
import Components from 'unplugin-vue-components/vite';
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers';

/**
 * Vite settings of the extension, used by extension/build.js for its two passes: the popup with the background
 * worker (ES modules), and the content script (one classic script, as Chrome runs content scripts).
 */
export function extensionConfig({ appUrl, clientId, content = false }) {
    const root = import.meta.dirname;

    return {
        root,
        configFile: false,
        publicDir: content ? false : path.resolve(root, 'public'),
        plugins: [
            vue(),
            // Element Plus on demand with its SCSS sources and the app's theme, as in the root vite.config.js.
            AutoImport({ dts: false, resolvers: [ElementPlusResolver({ importStyle: 'sass' })] }),
            Components({ dts: false, resolvers: [ElementPlusResolver({ importStyle: 'sass' })] }),
        ],
        css: {
            preprocessorOptions: {
                scss: {
                    additionalData: `@use "${path.resolve(root, '../resources/style/theme/variables.scss')}" as *;\n`,
                },
            },
        },
        define: {
            'import.meta.env.VITE_EXTENSION_APP_URL': JSON.stringify(appUrl),
            'import.meta.env.VITE_EXTENSION_CLIENT_ID': JSON.stringify(clientId),
        },
        build: {
            outDir: path.resolve(root, 'dist'),
            emptyOutDir: !content,
            // Chrome loads the files as they are; readable code is easier to review in the store.
            minify: false,
            rollupOptions: content
                ? {
                    input: { content: path.resolve(root, 'src/content/index.js') },
                    output: { format: 'iife', entryFileNames: '[name].js' },
                }
                : {
                    input: {
                        popup: path.resolve(root, 'popup.html'),
                        background: path.resolve(root, 'src/background/index.js'),
                    },
                    output: {
                        entryFileNames: '[name].js',
                        chunkFileNames: 'chunks/[name]-[hash].js',
                        assetFileNames: 'assets/[name]-[hash][extname]',
                    },
                },
        },
        logLevel: 'warn',
    };
}
