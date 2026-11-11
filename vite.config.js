import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/checklist.js', 'resources/js/date-picker.js', 'resources/js/photo-viewer.js', 'resources/js/password-toggle.js', 'resources/js/floor-plan-upload.js', 'resources/js/floor-plan-editor.js', 'resources/js/floor-plan-map.js', 'resources/js/chronology-form.js', 'resources/js/chat.js', 'resources/js/chat-widget.js', 'resources/js/pull-refresh.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
