import { useAiProcessingCore } from '@/composables/useAiProcessingCore';
import { usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { type Ref } from 'vue';

/**
 * Dashboard-only counterpart to useAiProcessing.ts — used where a page tracks many projects at
 * once (Dashboard/Index.vue) instead of one. Subscribes a single time on the org-wide channel
 * (every DocumentProcessingUpdate/DocumentVectorized broadcast already goes out there too, see
 * those events' broadcastOn()) rather than opening one project channel per project, since a
 * page like the dashboard has no per-project state to keep anyway — allDocs/targetBeingCreated
 * already span every tracked project (see useAiProcessingCore.ts).
 */
export function useOrgAiProcessing(
    organizationId: string,
    projectIds: Ref<string[]>,
    allDocs: Ref<ExtendedDocument[]>,
    targetBeingCreated: Ref<string | number | null>,
    onDocumentUpdated?: (doc: ExtendedDocument) => void,
    onSuccess?: (message: string) => void,
    onError?: (message: string) => void,
    onDocumentsRemoved?: (parentId: string | number) => void,
    reloadPropsOnNewDocuments?: string[],
) {
    const currentUserId = usePage<AppPageProps>().props.auth.user.id;

    const { aiStatusMessage, aiProgress, isAiProcessing, handlePayload } =
        useAiProcessingCore(
            allDocs,
            targetBeingCreated,
            currentUserId,
            onDocumentUpdated,
            onSuccess,
            onError,
            onDocumentsRemoved,
            reloadPropsOnNewDocuments,
        );

    useEcho(
        `organization.${organizationId}`,
        ['.document.vectorized', '.DocumentProcessingUpdate'],
        (payload: any) => {
            // The org channel carries every project's events, not just the ones this page
            // tracks (e.g. Dashboard only loads projects that have tasks — see
            // DashboardController::index()) — filter to those before handing off, so a
            // broadcast for an untracked project doesn't feed unknown ids into
            // onDocumentUpdated/onDocumentsRemoved. Fails open (processes the event) if a
            // payload is ever missing project_id rather than silently dropping it.
            const eventProjectId = payload.document?.project_id;
            if (
                eventProjectId != null &&
                !projectIds.value.includes(String(eventProjectId))
            ) {
                return;
            }

            handlePayload(payload);
        },
        [organizationId],
        'private',
    );

    return {
        aiStatusMessage,
        aiProgress,
        isAiProcessing,
    };
}
