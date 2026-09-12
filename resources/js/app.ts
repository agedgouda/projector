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

function currentInertiaVersion(): string | null {
    try {
        const page = document.getElementById('app')?.dataset.page;
        return page ? (JSON.parse(page).version ?? null) : null;
    } catch {
        return null;
    }
}

// A page chunk is code-split per Inertia page, so the first navigation to any given page
// fetches its .js fresh — if a deploy has since removed the file this tab's already-loaded
// bundle points at (see ProjectController/OrgDocumentController's create routes for the bug
// this was written for), that fetch 404s and throws instead of resolving. Inertia's own
// asset-version check normally forces a hard reload before this can happen, but it only
// covers requests that go through Inertia's router — this is the backstop for whatever gets
// past it. A stale tab only needs one reload to pick up the new bundle, so a short cooldown
// (sessionStorage survives the reload) stops a genuinely broken deploy from reload-looping.
const STALE_ASSET_RELOAD_KEY = 'staleAssetReloadAt';
const STALE_ASSET_RELOAD_COOLDOWN_MS = 15000;

function reportStaleAssetAndReload(chunk: string, error: unknown): Promise<never> {
    const message = error instanceof Error ? error.message : String(error);
    console.error(`Stale asset chunk failed to load (${chunk}):`, error);

    fetch('/client-logs/stale-asset', {
        method: 'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            chunk,
            message,
            page_url: window.location.href,
            client_version: currentInertiaVersion(),
        }),
    }).catch(() => {});

    const lastReloadAt = Number(sessionStorage.getItem(STALE_ASSET_RELOAD_KEY) ?? 0);
    if (Date.now() - lastReloadAt < STALE_ASSET_RELOAD_COOLDOWN_MS) {
        return Promise.reject(error);
    }

    sessionStorage.setItem(STALE_ASSET_RELOAD_KEY, String(Date.now()));
    window.location.reload();
    return new Promise(() => {});
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ).catch((error: unknown) => reportStaleAssetAndReload(name, error)),
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
