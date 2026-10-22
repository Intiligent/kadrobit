import { defineConfig } from 'vitest/config';
import path from 'path';

export default defineConfig({
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, 'resources/js'),
        },
    },
    test: {
        include: ['tests/js/**/*.test.js'],
        environment: 'node',
    },
});
