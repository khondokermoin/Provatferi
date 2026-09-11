import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// Single entry: the Provatferi brand/override stylesheet. Everything else
// on these pages (Zircos vendor CSS/JS, the theme-toggle script) is a plain
// static asset served from public/ and linked directly in the layouts —
// only this app-owned file needs content-hash fingerprinting so a change
// here is guaranteed to reach returning visitors (see provatferi-admin.css's
// own header comment for why that matters).
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/provatferi-admin.css'],
            refresh: true,
        }),
    ],
});
