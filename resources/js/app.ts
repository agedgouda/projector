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
// fetches its .js fresh — root cause not yet confirmed (a stale deploy hash isn't it: verified
// live that Inertia's own version check already forces a hard reload before this can happen on
// a real version mismatch). Whatever the trigger, the recovery is the same: one reload picks up
// a working bundle. A short cooldown (sessionStorage survives the reload) stops a genuinely
// unrecoverable failure from reload-looping forever.
const STALE_ASSET_RELOAD_KEY = 'staleAssetReloadAt';
const STALE_ASSET_RELOAD_COOLDOWN_MS = 15000;

// Reports are queued to localStorage *before* we ever try to send them, and flushed both here
// and on every future app boot — if the failure itself was caused by the network being down
// (the leading theory: a laptop waking from sleep, wifi/VPN reconnecting), the same outage
// would silently swallow a fire-and-forget POST made at the moment of failure. Persisting first
// means the report survives the reload and gets sent as soon as the app next boots with a
// working connection, instead of depending on that one request landing.
const STALE_ASSET_PENDING_KEY = 'pendingStaleAssetReports';

interface StaleAssetReport {
    chunk: string;
    message: string;
    page_url: string;
    client_version: string | null;
}

function queueStaleAssetReport(report: StaleAssetReport): void {
    try {
        const pending: StaleAssetReport[] = JSON.parse(
            localStorage.getItem(STALE_ASSET_PENDING_KEY) ?? '[]',
        );
        pending.push(report);
        localStorage.setItem(STALE_ASSET_PENDING_KEY, JSON.stringify(pending));
    } catch {
        // Private browsing / storage disabled / quota exceeded — nothing more we can do
        // client-side; the best-effort send below is this report's only shot.
    }
}

async function flushPendingStaleAssetReports(): Promise<void> {
    let pending: StaleAssetReport[];
    try {
        pending = JSON.parse(localStorage.getItem(STALE_ASSET_PENDING_KEY) ?? '[]');
    } catch {
        return;
    }
    if (pending.length === 0) {
        return;
    }

    localStorage.removeItem(STALE_ASSET_PENDING_KEY);

    for (const report of pending) {
        try {
            const response = await fetch('/client-logs/stale-asset', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(report),
            });
            if (!response.ok) {
                throw new Error(`status ${response.status}`);
            }
        } catch {
            queueStaleAssetReport(report);
        }
    }
}

function reportStaleAssetAndReload(chunk: string, error: unknown): Promise<never> {
    const message = error instanceof Error ? error.message : String(error);
    console.error(`Stale asset chunk failed to load (${chunk}):`, error);

    queueStaleAssetReport({
        chunk,
        message,
        page_url: window.location.href,
        client_version: currentInertiaVersion(),
    });
    flushPendingStaleAssetReports().catch(() => {});

    const lastReloadAt = Number(sessionStorage.getItem(STALE_ASSET_RELOAD_KEY) ?? 0);
    if (Date.now() - lastReloadAt < STALE_ASSET_RELOAD_COOLDOWN_MS) {
        return Promise.reject(error);
    }

    sessionStorage.setItem(STALE_ASSET_RELOAD_KEY, String(Date.now()));
    window.location.reload();
    return new Promise(() => {});
}

// Catches reports that were queued but never successfully sent before the reload (e.g. the
// network was still down at the moment of failure) — every successful app boot gets another
// chance to flush them.
flushPendingStaleAssetReports().catch(() => {});

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
