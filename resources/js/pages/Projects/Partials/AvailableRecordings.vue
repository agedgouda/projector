<script setup lang="ts">
import { computed, ref } from 'vue';
import { Download } from 'lucide-vue-next';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ImportConfirmModal from '@/components/recordings/ImportConfirmModal.vue';
import RecordingsList from '@/components/recordings/RecordingsList.vue';
import { useTranscriptActions } from '@/composables/transcripts/useTranscriptActions';
import { type ImportTypeChoice } from '@/composables/transcripts/useDocumentImportActions';
import { INTAKE_KEY } from '@/composables/useWorkflow';

const props = withDefaults(defineProps<{
    projectId: string;
    recordings: Recording[];
    importedIds: string[];
    crossProjectImportedIds: string[];
    canManage: boolean;
    providerError?: string | null;
    // Which type a picked recording becomes — resolved the same way a Google Doc/file's is
    // (see DocumentTypeResolver). Defaults to the intake type for the two contexts that don't
    // offer a type picker at all (the standalone Transcripts tab, Show.vue's old Recordings
    // tab) — only the Import a Document modal ever passes something else.
    typeChoice?: ImportTypeChoice;
}>(), {
    typeChoice: () => ({ type: INTAKE_KEY }),
});

// Whether the picked type is Transcription — decides both whether the row's inline AI-prompt
// popover makes sense (see RecordingsList's showAiPrompt prop) and whether importing needs a
// confirm-first step at all: the confirmation dialog exists specifically to pause before the
// "generate Meeting Notes via AI" step, so it has nothing to confirm for any other type — a
// non-Transcription pick imports immediately, exactly like a Google Doc/file picked as that
// same type does.
const isTranscription = computed(() => 'type' in props.typeChoice && props.typeChoice.type === INTAKE_KEY);

const excludedIds = computed(() => [...props.importedIds, ...props.crossProjectImportedIds]);

const emit = defineEmits<{
    importQueued: [];
    importFailed: [];
}>();

const {
    importing,
    importRecording,
    isDismissRecordingOpen,
    recordingToDismiss,
    dismissingRecording,
    confirmDismissRecording,
    closeDismissRecording,
    handleDismissRecording,
} = useTranscriptActions(props.projectId, {
    onImportQueued: () => emit('importQueued'),
    onImportFailed: () => emit('importFailed'),
});

// Import only pauses for the confirmation modal when Transcription is picked — that dialog
// exists to confirm the AI-generation step, so any other type imports immediately on click,
// exactly like Google Doc/file do (see ImportDocumentOptions.vue's own handleItemPicked).
const pendingImportRecording = ref<Recording | null>(null);
const isImportConfirmOpen = ref(false);

const openImportConfirm = (recording: Recording) => {
    pendingImportRecording.value = recording;
    isImportConfirmOpen.value = true;
};

const closeImportConfirm = () => {
    isImportConfirmOpen.value = false;
};

const confirmImport = (additionalInfo: string | null) => {
    if (!pendingImportRecording.value) return;
    importRecording(pendingImportRecording.value, additionalInfo, props.typeChoice);
    isImportConfirmOpen.value = false;
};

const actions = computed<RecordingAction[]>(() => [
    {
        label: 'Import',
        icon: Download,
        variant: 'primary',
        loading: (recording) => importing.value === recording.id,
        onClick: (recording) => {
            if (isTranscription.value) {
                openImportConfirm(recording);
                return;
            }
            importRecording(recording, null, props.typeChoice);
        },
    },
]);
</script>

<template>
    <RecordingsList
        :recordings="recordings"
        :excluded-ids="excludedIds"
        :can-manage="canManage"
        :provider-error="providerError"
        :actions="actions"
        :on-dismiss="confirmDismissRecording"
        :show-ai-prompt="false"
    />

    <ConfirmDeleteModal
        :open="isDismissRecordingOpen"
        :title="`Remove &quot;${recordingToDismiss?.title}&quot;?`"
        description="This recording will be hidden from the available list and won't be importable into this project."
        :loading="dismissingRecording"
        @close="closeDismissRecording"
        @confirm="handleDismissRecording"
    />

    <ImportConfirmModal
        :open="isImportConfirmOpen"
        :item-title="pendingImportRecording?.title"
        :loading="importing === pendingImportRecording?.id"
        @close="closeImportConfirm"
        @confirm="confirmImport"
    />
</template>
