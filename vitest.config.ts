import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Kept apart from vite.config.ts so the Laravel plugin (which expects a running app) stays out of tests.
// The tests cover the plain logic in resources/js/lib and the tracker's composables; the UI is checked in a browser.
export default defineConfig({
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
});
