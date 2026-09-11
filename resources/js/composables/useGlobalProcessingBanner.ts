import { computed, reactive } from 'vue';

export interface BannerEntry {
    title: string;
    message: string;
    progress: number;
    priority: number;
}

/**
 * Fixed priority tiers, highest wins when more than one source is genuinely active at once
 * (the concrete case: Projects/Show can have a document AI-processing while a task-list import
 * also runs). An explicit, just-clicked task-list import outranks background AI processing
 * (whose own copy says "you can keep working"), which outranks the org-wide background
 * fallback (activity elsewhere, or on a page you've navigated away from) — the least specific
 * signal of the three.
 */
export const BANNER_PRIORITY = {
    TASK_LIST_IMPORT: 20,
    AI_PROCESSING: 10,
    BACKGROUND_FALLBACK: 0,
} as const;

// Module-level singleton (same pattern as useProcessingReconciler.ts/state.js) — every page
// with its own AI-processing state registers its current title/message/progress here under a
// fixed string key instead of rendering its own <AiProcessingHeader>. AppLayout.vue is the only
// place that actually renders one, so at most a single banner is ever visible at once instead
// of stacking a page-specific one on top of the org-wide fallback.
const entries = reactive(new Map<string, BannerEntry>());

function setBanner(id: string, entry: BannerEntry): void {
    entries.set(id, entry);
}

function clearBanner(id: string): void {
    entries.delete(id);
}

const activeBanner = computed<BannerEntry | null>(() => {
    let best: BannerEntry | null = null;
    for (const entry of entries.values()) {
        if (!best || entry.priority > best.priority) {
            best = entry;
        }
    }
    return best;
});

export function useGlobalProcessingBanner() {
    return { activeBanner, setBanner, clearBanner };
}
