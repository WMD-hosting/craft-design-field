import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import {defineConfig} from 'vite';

// The control panel shares Vue, Inertia and @craftcms/cp through its import map, so the
// bundle leaves them out (a second Vue could not render Craft's components). Vite's dev
// server does not apply these externals: build, or `npm run watch` while working.
export default defineConfig({
    // Craft serves the build from vendor/wmd/craft-design-field/build/, not /build/: a relative
    // base makes the lazy control chunk and its CSS load next to cp.js, wherever that is.
    base: './',
    plugins: [
        laravel({input: ['resources/js/cp.ts'], publicDirectory: 'public', buildDirectory: 'build'}),
        vue({
            template: {
                compilerOptions: {
                    isCustomElement: (tag) => tag.startsWith('craft-'),
                },
            },
        }),
    ],
    build: {
        rollupOptions: {
            external: ['vue', '@inertiajs/vue3', '@craftcms/cp', '@craftcms/garnish'],
        },
    },
});
