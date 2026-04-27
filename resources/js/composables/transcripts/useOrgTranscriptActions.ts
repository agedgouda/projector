import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import orgDocumentsRoutes from '@/routes/organizations/documents/index';
import organizationRoutes from '@/routes/organizations/index';

export function useOrgTranscriptActions(organizationId: string, orgDocumentId?: string) {
    const importing = ref<string | null>(null);

    const importRecording = (recording: Recording) => {
        importing.value = recording.id;

        const url = orgDocumentId
            ? orgDocumentsRoutes.importRecording({ organization: organizationId, orgDocument: orgDocumentId }).url
            : organizationRoutes.importRecording(organizationId).url;

        router.post(url, {
            recording_id: recording.id,
            title: recording.title,
            started_at: recording.started_at,
        }, {
            preserveScroll: true,
            onError: (errors) => {
                toast.error('Import failed', { description: Object.values(errors)[0] as string });
            },
            onFinish: () => { importing.value = null; },
        });
    };

    return {
        importing,
        importRecording,
    };
}
