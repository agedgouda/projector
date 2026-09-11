import { useAiProcessingCore } from '@/composables/useAiProcessingCore';
import { usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { type Ref } from 'vue';

export function useAiProcessing(
    projectId: string,
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

    // Listens for both specific document updates and general processing progress
    useEcho(
        `project.${projectId}`,
        ['.document.vectorized', '.DocumentProcessingUpdate'],
        handlePayload,
        [projectId],
        'private',
    );

    return {
        aiStatusMessage,
        aiProgress,
        isAiProcessing,
    };
}
