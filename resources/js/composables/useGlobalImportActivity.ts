import { parseProcessingStatus } from '@/lib/aiProcessingStatus';
import { isProcessingMine } from '@/lib/isProcessingMine';
import { usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, ref } from 'vue';

interface GlobalActivityPayload {
    statusMessage?: string;
    document_id?: string;
    document?: {
        processing_triggered_by_user_id?: number | null;
        creator_id?: number | null;
    };
    progress?: number;
    import_document_id?: string;
    status?: 'running' | 'done' | 'error';
}

// A genuinely still-running import/AI job re-broadcasts well within this window (TaskListImport
// broadcasts ~50 times over its run; DocumentProcessingUpdate fires per document/child). If
// nothing is heard for an id for this long, treat it as abandoned rather than trust it forever —
// the terminal (done/success/error) broadcast for it was likely dropped, or whatever produced it
// crashed without ever reaching the code path that reports failure. Without this, one missed
// broadcast anywhere in the org leaves the banner stuck showing "in progress" permanently, since
// unlike a single project page there's no per-org "what's actually still running" check to fall
// back on.
const STALE_AFTER_MS = 5 * 60 * 1000;
const PRUNE_INTERVAL_MS = 30 * 1000;

/**
 * App-shell-level counterpart to useAiProcessing.ts / useTaskListImportProgress.ts, which only
 * track activity for whichever single project is currently open. Mounted once from
 * AppLayout.vue so a slow import the current user kicked off — directly, or via a Slack file
 * upload they initiated — still shows *something's* running after they've navigated away from
 * that project.
 *
 * Gated to the current user's own activity, same rule as every other AiProcessingHeader surface
 * (see isProcessingMine.ts): DocumentProcessingUpdate still broadcasts org-wide (other pages'
 * data sync needs that), so this filters incoming document payloads client-side by ownership;
 * TaskListImportProgress broadcasts only on the private user.{id} channel now
 * (TaskListImportProgress::broadcastOn()), so no filtering is needed there — the channel itself
 * is already scoped.
 *
 * Deliberately live-broadcast-only, with no percentage shown: unlike a single project page,
 * more than one import can be in flight across the org at once, so there's no single
 * meaningful progress number to display, and reconstructing "what's already running" at
 * page-load time would need a persisted server-side flag that doesn't otherwise exist. In
 * practice a fresh mount mid-import still catches the "still running" signal within moments,
 * since both broadcasts fire repeatedly over an import's duration rather than just once.
 */
export function useGlobalImportActivity() {
    const page = usePage<AppPageProps>();
    const activeOrgId = computed(() => page.props.auth.active_org_id);
    const currentUserId = page.props.auth.user.id;

    // Each id maps to the timestamp it was last confirmed still-running, so a stale one can be
    // pruned without waiting on a terminal broadcast that may never arrive.
    const activeDocumentIds = ref(new Map<string, number>());
    const activeImportIds = ref(new Map<string, number>());

    const isProcessing = computed(
        () =>
            activeDocumentIds.value.size > 0 || activeImportIds.value.size > 0,
    );

    const pruneStale = () => {
        const cutoff = Date.now() - STALE_AFTER_MS;

        const freshDocumentIds = new Map(
            [...activeDocumentIds.value].filter(
                ([, seenAt]) => seenAt >= cutoff,
            ),
        );
        if (freshDocumentIds.size !== activeDocumentIds.value.size) {
            activeDocumentIds.value = freshDocumentIds;
        }

        const freshImportIds = new Map(
            [...activeImportIds.value].filter(([, seenAt]) => seenAt >= cutoff),
        );
        if (freshImportIds.size !== activeImportIds.value.size) {
            activeImportIds.value = freshImportIds;
        }
    };

    const pruneTimer = setInterval(pruneStale, PRUNE_INTERVAL_MS);
    onBeforeUnmount(() => clearInterval(pruneTimer));

    useEcho<GlobalActivityPayload>(
        `organization.${activeOrgId.value}`,
        ['.DocumentProcessingUpdate'],
        (payload) => {
            if (
                payload.statusMessage &&
                payload.document_id &&
                isProcessingMine(payload.document, currentUserId)
            ) {
                const { isSuccess, isError } = parseProcessingStatus(payload);
                const ids = new Map(activeDocumentIds.value);
                if (isSuccess || isError) {
                    ids.delete(payload.document_id);
                } else {
                    ids.set(payload.document_id, Date.now());
                }
                activeDocumentIds.value = ids;
            }
        },
        [activeOrgId.value],
        'private',
    );

    useEcho<GlobalActivityPayload>(
        `user.${currentUserId}`,
        ['.TaskListImportProgress'],
        (payload) => {
            if (payload.import_document_id) {
                const ids = new Map(activeImportIds.value);
                if (payload.status === 'running') {
                    ids.set(payload.import_document_id, Date.now());
                } else {
                    ids.delete(payload.import_document_id);
                }
                activeImportIds.value = ids;
            }
        },
        [currentUserId],
        'private',
    );

    return { isProcessing };
}
