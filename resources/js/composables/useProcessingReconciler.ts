import axios from 'axios';
import { ref } from 'vue';

// This poll exists purely as a hedge against one specific broadcast getting lost in transit —
// the connection itself never even drops, so nothing else notices — which should be rare.
// Deliberately generous rather than tight: most jobs finish (and stop needing this at all) well
// before the interval ever fires, so a slower cadence just means a slightly longer worst-case
// recovery for the rare genuinely-dropped message, not a worse common case.
const POLL_INTERVAL_MS = 60000;

// Most jobs finish via their own live broadcast well within this window, so delaying the first
// check this long means the typical case (broadcast arrives normally) never calls
// /processing-status at all — this only ever fires for a job that's actually still running
// after a while, which is exactly the case worth checking on.
const POLL_START_DELAY_MS = 30000;

// Module-level singleton — one shared poll for the whole app, not one per composable instance
// (same pattern as globalAiState in @/state). processingDocumentIds/processingOrgDocumentIds
// are the server's own answer to "what's still processing," refreshed on this one timer;
// consumers diff their own tracked ids against these sets instead of blindly reloading on
// every tick.
const processingDocumentIds = ref<Set<string>>(new Set());
const processingOrgDocumentIds = ref<Set<string>>(new Set());

let activeWatchers = 0;
let timer: ReturnType<typeof setInterval> | null = null;
let startDelayTimer: ReturnType<typeof setTimeout> | null = null;

async function refresh(): Promise<void> {
    const { data } = await axios.get('/processing-status');
    processingDocumentIds.value = new Set(
        (data.processing_document_ids ?? []).map(String),
    );
    processingOrgDocumentIds.value = new Set(
        (data.processing_org_document_ids ?? []).map(String),
    );
}

// Skips the request entirely when nothing is actually being tracked right now — the one-shot
// triggers below (bfcache restore, socket reconnect) can fire at moments unrelated to any
// processing job at all, and there's nothing to reconcile against if so.
function refreshIfWatching(): void {
    if (activeWatchers > 0) {
        refresh();
    }
}

function beginPolling(): void {
    if (timer) return;
    refresh();
    timer = setInterval(refresh, POLL_INTERVAL_MS);
}

// Reference-counted so the interval only runs while at least one consumer currently believes
// something is processing — mirrors the on/off lifecycle each of the 3 pollers had on its own,
// just shared across all of them now. The actual polling only starts once POLL_START_DELAY_MS
// has passed with a watcher still active — a job that finishes via its own broadcast before
// then (the common case) cancels this without ever having made a request.
function start(): void {
    activeWatchers++;
    if (activeWatchers === 1 && !timer && !startDelayTimer) {
        startDelayTimer = setTimeout(() => {
            startDelayTimer = null;
            if (activeWatchers > 0) beginPolling();
        }, POLL_START_DELAY_MS);
    }
}

function stop(): void {
    activeWatchers = Math.max(0, activeWatchers - 1);
    if (activeWatchers === 0) {
        if (startDelayTimer) {
            clearTimeout(startDelayTimer);
            startDelayTimer = null;
        }
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }
}

// One-shot correction for a browser back/forward navigation that restores a cached page
// snapshot entirely client-side (no server round-trip, no broadcast involved at all) — see the
// `persisted` flag on the pageshow event. If a tracked job finished while this tab was
// navigated away from, the restored snapshot still shows the pre-completion state; this catches
// it immediately instead of waiting on however much of POLL_START_DELAY_MS/POLL_INTERVAL_MS is
// left. Registered once at module load (this file's state is a singleton either way), not per
// composable instance.
if (typeof window !== 'undefined') {
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            refreshIfWatching();
        }
    });
}

export function useProcessingReconciler() {
    return {
        processingDocumentIds,
        processingOrgDocumentIds,
        start,
        stop,
        // Also used by useEchoWatchdog.ts as a one-shot correction right after a dropped
        // socket reconnects, for the same reason as the pageshow listener above.
        refresh: refreshIfWatching,
    };
}
