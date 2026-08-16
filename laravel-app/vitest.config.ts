import { fileURLToPath, URL } from 'node:url';

import { defineConfig } from 'vitest/config';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js/spa', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['resources/js/spa/**/*.test.ts'],
    },
});
