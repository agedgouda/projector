import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import '../css/app.css';
import { initializeTheme } from './composables/useAppearance';

import { router } from '@inertiajs/vue3';
declare global {
    interface Window {
        appHasHistory: boolean;
    }
}
// VITE_REVERB_HOST just inherits REVERB_HOST by default (see .env/.env.example), which is
// "0.0.0.0" — the server's bind-all address, not a host a browser can open a WebSocket to.
// Left as-is, every private-channel subscription silently lands in Pusher's "unavailable"
// connection state, so AiProcessingHeader-style live progress (AI sync, list imports) never
// updates and never fires its completion redirect, with no visible error. Fall back to the
// page's own hostname whenever the configured value is missing or is that unroutable address.
const reverbHost = import.meta.env.VITE_REVERB_HOST;
const resolvedReverbHost =
    reverbHost && reverbHost !== '0.0.0.0'
        ? reverbHost
        : window.location.hostname;

// Keep your existing plugin config as well
configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: resolvedReverbHost,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Add a simple global variable or reactive ref
window.appHasHistory = false;

router.on('finish', () => {
    window.appHasHistory = true;
});

// The one place to change the brand color used for both the page-navigation progress bar
// below and AiProcessingHeader.vue's AI-sync progress bar — both read --color-projector-
// primary-600 (defined once, in app.css) instead of each hardcoding their own value.
const brandColor = getComputedStyle(document.documentElement)
    .getPropertyValue('--color-projector-primary-600')
    .trim();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: brandColor,
    },
});

initializeTheme();
