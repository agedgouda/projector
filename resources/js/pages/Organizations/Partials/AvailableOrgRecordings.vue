<script setup lang="ts">
import { computed } from 'vue';
import { Download } from 'lucide-vue-next';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import RecordingsList from '@/components/recordings/RecordingsList.vue';
import { useOrgTranscriptActions } from '@/composables/transcripts/useOrgTranscriptActions';

const props = defineProps<{
    organizationId: string;
    orgDocumentId?: string;
    recordings: Recording[];
    importedIds: string[];
    canManage: boolean;
    providerError?: string | null;
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
} = useOrgTranscriptActions(props.organizationId, props.orgDocumentId);

const actions = computed<RecordingAction[]>(() => [
    {
        label: 'Import',
        icon: Download,
        variant: 'primary',
        loading: (recording) => importing.value === recording.id,
        onClick: (recording, customPrompt) => importRecording(recording, customPrompt),
    },
]);
</script>

<template>
    <RecordingsList
        :recordings="recordings"
        :excluded-ids="importedIds"
        :can-manage="canManage"
        :provider-error="providerError"
        :actions="actions"
        :on-dismiss="confirmDismissRecording"
    />

    <ConfirmDeleteModal
        :open="isDismissRecordingOpen"
        :title="`Remove &quot;${recordingToDismiss?.title}&quot;?`"
        description="This recording will be hidden from the available list and won't be importable as a status meeting."
        :loading="dismissingRecording"
        @close="closeDismissRecording"
        @confirm="handleDismissRecording"
    />
</template>
