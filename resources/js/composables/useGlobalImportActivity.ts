import { parseProcessingStatus } from '@/lib/aiProcessingStatus';
import { usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { computed, ref } from 'vue';

interface GlobalActivityPayload {
    statusMessage?: string;
    document_id?: string;
    progress?: number;
    import_document_id?: string;
    status?: 'running' | 'done' | 'error';
}

/**
 * App-shell-level counterpart to useAiProcessing.ts / useTaskListImportProgress.ts, which only
 * track activity for whichever single project is currently open. Mounted once from
 * AppLayout.vue so a slow import someone kicked off — directly, or via a Slack file upload,
 * where the person watching the page may not even be the one who started it — still shows
 * *something's* running after they've navigated away from that project.
 *
 * Organization-wide rather than per-user: see DocumentProcessingUpdate::broadcastOn() and
 * TaskListImportProgress::broadcastOn(), which now also broadcast on 'organization.{id}'
 * alongside their existing 'project.{id}' channel.
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

    const activeDocumentIds = ref(new Set<string>());
    const activeImportIds = ref(new Set<string>());

    const isProcessing = computed(
        () =>
            activeDocumentIds.value.size > 0 || activeImportIds.value.size > 0,
    );

    useEcho<GlobalActivityPayload>(
        `organization.${activeOrgId.value}`,
        ['.DocumentProcessingUpdate', '.TaskListImportProgress'],
        (payload) => {
            if (payload.import_document_id) {
                const ids = new Set(activeImportIds.value);
                if (payload.status === 'running') {
                    ids.add(payload.import_document_id);
                } else {
                    ids.delete(payload.import_document_id);
                }
                activeImportIds.value = ids;
                return;
            }

            if (payload.statusMessage && payload.document_id) {
                const { isSuccess, isError } = parseProcessingStatus(payload);
                const ids = new Set(activeDocumentIds.value);
                if (isSuccess || isError) {
                    ids.delete(payload.document_id);
                } else {
                    ids.add(payload.document_id);
                }
                activeDocumentIds.value = ids;
            }
        },
        [activeOrgId.value],
        'private',
    );

    return { isProcessing };
}
