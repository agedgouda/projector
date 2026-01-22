import { ref } from 'vue';
import { useEcho } from '@laravel/echo-vue';
import { toast } from 'vue-sonner';

export function useDocumentRealtime(projectId: string | number) {
    const aiStatusMessage = ref('');
    const lastIncomingDoc = ref<any>(null);

    useEcho(
        `project.${projectId}`,
        [
            '.document.vectorized',
            '.DocumentProcessingUpdate',
            '.App\\Events\\DocumentProcessingUpdate'
        ],
        (payload: any) => {
            // 1. Handle Text Status Updates
            if (payload.statusMessage) {
                aiStatusMessage.value = payload.statusMessage;
                return;
            }

            // 2. Handle Document Data Updates
            if (payload.document) {
                aiStatusMessage.value = ''; // Clear status on completion
                lastIncomingDoc.value = payload.document;
                toast.success('Project Updated');
            }
        },
        [projectId],
        'private'
    );

    return {
        aiStatusMessage,
        lastIncomingDoc
    };
}
