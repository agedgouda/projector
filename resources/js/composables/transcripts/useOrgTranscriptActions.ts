import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import orgDocumentsRoutes from '@/routes/organizations/documents/index';
import organizationRoutes from '@/routes/organizations/index';

export function useOrgTranscriptActions(organizationId: string, orgDocumentId?: string) {
    // ── Import ────────────────────────────────────────────────────────────────

    const importing = ref<string | null>(null);

    const importRecording = (recording: Recording, customPrompt?: string | null) => {
        importing.value = recording.id;

        const url = orgDocumentId
            ? orgDocumentsRoutes.importRecording({ organization: organizationId, orgDocument: orgDocumentId }).url
            : organizationRoutes.importRecording(organizationId).url;

        router.post(url, {
            recording_id: recording.id,
            title: recording.title,
            started_at: recording.started_at,
            custom_prompt: customPrompt || null,
        }, {
            preserveScroll: true,
            onError: (errors) => {
                toast.error('Import failed', { description: Object.values(errors)[0] as string });
            },
            onFinish: () => { importing.value = null; },
        });
    };

    // ── Dismiss ───────────────────────────────────────────────────────────────

    const isDismissRecordingOpen = ref(false);
    const recordingToDismiss = ref<Recording | null>(null);
    const dismissingRecording = ref(false);

    const confirmDismissRecording = (recording: Recording) => {
        recordingToDismiss.value = recording;
        isDismissRecordingOpen.value = true;
    };

    const closeDismissRecording = () => {
        isDismissRecordingOpen.value = false;
        setTimeout(() => { recordingToDismiss.value = null; }, 200);
    };

    const handleDismissRecording = () => {
        if (!recordingToDismiss.value) { return; }
        dismissingRecording.value = true;
        router.post(organizationRoutes.dismissRecording(organizationId).url, {
            recording_id: recordingToDismiss.value.id,
        }, {
            preserveScroll: true,
            onSuccess: () => { closeDismissRecording(); },
            onFinish: () => { dismissingRecording.value = false; },
        });
    };

    return {
        importing,
        importRecording,
        isDismissRecordingOpen,
        recordingToDismiss,
        dismissingRecording,
        confirmDismissRecording,
        closeDismissRecording,
        handleDismissRecording,
    };
}
