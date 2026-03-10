import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import transcriptRoutes from '@/routes/projects/transcripts/index';

export function useTranscriptActions(projectId: string, callbacks?: {
    onImportQueued?: () => void;
    onImportFailed?: () => void;
}) {
    // ── Import ────────────────────────────────────────────────────────────────

    const importing = ref<string | null>(null);

    const importRecording = (recording: Recording) => {
        importing.value = recording.id;
        callbacks?.onImportQueued?.();

        router.post(transcriptRoutes.store.url(projectId), {
            recording_id: recording.id,
            title: recording.title,
            started_at: recording.started_at,
        }, {
            preserveScroll: true,
            onError: (errors) => {
                toast.error('Import failed', { description: Object.values(errors)[0] as string });
                callbacks?.onImportFailed?.();
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
        router.post(transcriptRoutes.destroy.url({ project: projectId }), {
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
