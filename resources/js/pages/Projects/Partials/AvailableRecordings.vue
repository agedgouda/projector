<script setup lang="ts">
import { computed, ref } from 'vue';
import { Download } from 'lucide-vue-next';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ImportRecordingConfirmModal from '@/components/recordings/ImportRecordingConfirmModal.vue';
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

// "Import" no longer fires immediately — it opens a confirmation modal (below) with its own
// field for any last-minute additional information, which is what actually triggers
// importRecording() once the user clicks Save there.
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
    importRecording(pendingImportRecording.value, additionalInfo);
    isImportConfirmOpen.value = false;
};

const actions = computed<RecordingAction[]>(() => [
    {
        label: 'Import',
        icon: Download,
        variant: 'primary',
        loading: (recording) => importing.value === recording.id,
        onClick: (recording) => openImportConfirm(recording),
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

    <ImportRecordingConfirmModal
        :open="isImportConfirmOpen"
        :recording-title="pendingImportRecording?.title"
        :loading="importing === pendingImportRecording?.id"
        @close="closeImportConfirm"
        @confirm="confirmImport"
    />
</template>
