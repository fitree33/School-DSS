import { fileURLToPath, URL } from 'node:url';

import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/spa/main.tsx',
            ],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js/spa', import.meta.url)),
        },
    },
});
