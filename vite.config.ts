import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },

    server: {
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**', '**/storage/logs/**'],
        },
    },

    build: {
        // Every page is a separate chunk, fetched the first time it is opened
        // and cached by the browser afterwards. The alternative — one bundle —
        // means somebody who only ever opens Orders still downloads Payroll,
        // Reports and the layout builder before the first screen paints.
        //
        // The vendor split is deliberate rather than automatic: React and the
        // router change a few times a year, application code changes
        // daily, and keeping them in one file would expire the whole download
        // on every deploy.
        rollupOptions: {
            output: {
                // A function rather than a name-to-modules map. The map form
                // names entry points and lets Rollup decide where their
                // transitive dependencies land, which here produced an empty
                // "react" chunk and React itself bundled in with application
                // code — so a deploy that changed one component expired the
                // framework download too. Matching on the resolved path puts
                // every file where it was meant to go.
                manualChunks(id: string) {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }

                    if (id.includes('react-dom') || /node_modules[\\/]react[\\/]/.test(id)) {
                        return 'react';
                    }

                    if (id.includes('react-router')) {
                        return 'router';
                    }

                    if (id.includes('@tanstack')) {
                        return 'query';
                    }

                    return undefined;
                },
            },
        },
        // A warning at 500 kB is noise for a chunk that is deliberately the
        // framework; it is not noise at 900 kB, which would mean something
        // application-sized has leaked into it.
        chunkSizeWarningLimit: 900,
        sourcemap: false,
        cssCodeSplit: true,
    },
});
