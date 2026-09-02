import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import transcriptRoutes from '@/routes/projects/transcripts/index';
import { type ImportTypeChoice } from '@/composables/transcripts/useDocumentImportActions';
import { INTAKE_KEY } from '@/composables/useWorkflow';

export function useTranscriptActions(projectId: string, callbacks?: {
    onImportQueued?: () => void;
    onImportFailed?: () => void;
}) {
    // ── Import ────────────────────────────────────────────────────────────────

    const importing = ref<string | null>(null);

    // Which pipeline this ends up on the backend is decided purely by typeChoice, resolved the
    // same way a Google Doc/file's is (see DocumentTypeResolver) — a recording is no longer
    // unconditionally imported as the intake type just because it's a recording. Defaults to
    // the intake type for the two contexts that don't offer a picker at all (the standalone
    // Transcripts tab, Show.vue's old Recordings tab).
    const importRecording = (recording: Recording, customPrompt?: string | null, typeChoice: ImportTypeChoice = { type: INTAKE_KEY }) => {
        importing.value = recording.id;
        callbacks?.onImportQueued?.();

        router.post(transcriptRoutes.store.url(projectId), {
            recording_id: recording.id,
            title: recording.title,
            started_at: recording.started_at,
            custom_prompt: customPrompt || null,
            type: 'type' in typeChoice ? typeChoice.type : null,
            new_type_label: 'newTypeLabel' in typeChoice ? typeChoice.newTypeLabel : null,
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
