<script setup lang="ts">
import { computed } from 'vue';
import { Download, FileText } from 'lucide-vue-next';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import RecordingsList from '@/components/recordings/RecordingsList.vue';
import { useTranscriptActions } from '@/composables/transcripts/useTranscriptActions';

const props = defineProps<{
    projectId: string;
    recordings: Recording[];
    importedIds: string[];
    crossProjectImportedIds: string[];
    canManage: boolean;
    providerError?: string | null;
}>();

const excludedIds = computed(() => [...props.importedIds, ...props.crossProjectImportedIds]);

const emit = defineEmits<{
    importQueued: [];
    importFailed: [];
}>();

const {
    importing,
    importingAsRequirements,
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

// Either button disables both (prevents double-submitting the same recording under two
// different types at once) while only the one actually in flight shows its own spinner.
const isBusy = (recording: Recording) =>
    importing.value === recording.id || importingAsRequirements.value === recording.id;

const actions = computed<RecordingAction[]>(() => [
    {
        label: 'Import',
        icon: Download,
        variant: 'primary',
        loading: (recording) => importing.value === recording.id,
        disabled: isBusy,
        onClick: (recording, customPrompt) => importRecording(recording, 'intake', customPrompt),
    },
    {
        label: 'Requirements',
        icon: FileText,
        variant: 'outline',
        loading: (recording) => importingAsRequirements.value === recording.id,
        disabled: isBusy,
        onClick: (recording, customPrompt) => importRecording(recording, 'requirements', customPrompt),
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
    />

    <ConfirmDeleteModal
        :open="isDismissRecordingOpen"
        :title="`Remove &quot;${recordingToDismiss?.title}&quot;?`"
        description="This recording will be hidden from the available list and won't be importable into this project."
        :loading="dismissingRecording"
        @close="closeDismissRecording"
        @confirm="handleDismissRecording"
    />
</template>
