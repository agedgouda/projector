<script setup lang="ts">
import { computed } from 'vue';
import { AlertCircle, Download, Loader2, Trash2, Video } from 'lucide-vue-next';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import { useTranscriptActions } from '@/composables/transcripts/useTranscriptActions';
import { formatDate } from '@/lib/utils';

const props = defineProps<{
    projectId: string;
    recordings: Recording[];
    importedIds: string[];
    crossProjectImportedIds: string[];
    canManage: boolean;
    providerError?: string | null;
}>();

const pendingRecordings = computed(() =>
    props.recordings.filter(r =>
        !props.importedIds.includes(r.id) && !props.crossProjectImportedIds.includes(r.id)
    )
);

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
</script>

<template>
    <div v-if="providerError" class="flex items-start gap-4 rounded-2xl border border-red-200 bg-red-50 dark:bg-red-950/20 dark:border-red-800 p-6">
        <AlertCircle class="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
        <div>
            <p class="font-bold text-sm text-red-800 dark:text-red-300">Failed to connect to meeting provider</p>
            <p class="text-sm text-red-700 dark:text-red-400 mt-1 font-mono break-all">{{ providerError }}</p>
        </div>
    </div>

    <section v-else>
        <h2 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-4">
            Available Recordings
            <span class="ml-2 text-gray-300">(last 30 days)</span>
        </h2>

        <div v-if="pendingRecordings.length === 0" class="flex flex-col items-center justify-center py-16 rounded-2xl border border-dashed border-gray-200 bg-gray-50/50 dark:bg-gray-900/30 dark:border-gray-700">
            <Video class="w-8 h-8 text-gray-300 mb-3" />
            <p class="text-sm font-bold text-gray-500">No new recordings found</p>
            <p class="text-xs text-gray-400 mt-1">All recent recordings have already been imported, or none exist yet.</p>
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="recording in pendingRecordings"
                :key="recording.id"
                class="flex items-center gap-4 p-4 rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:border-indigo-200 dark:hover:border-indigo-700 transition-colors"
            >
                <div class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950 shrink-0">
                    <Video class="w-4 h-4 text-indigo-500" />
                </div>

                <div class="flex-1 min-w-0">
                    <p class="font-bold text-sm text-gray-900 dark:text-white truncate">{{ recording.title }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">
                        {{ formatDate(recording.started_at) }}
                        <span v-if="recording.duration_minutes" class="ml-2">
                            · {{ recording.duration_minutes }} min
                        </span>
                    </p>
                </div>

                <template v-if="canManage">
                    <Button
                        size="sm"
                        :disabled="importing === recording.id"
                        class="shrink-0 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-4 h-9 text-[10px] font-black uppercase tracking-widest"
                        @click="importRecording(recording)"
                    >
                        <Loader2 v-if="importing === recording.id" class="w-3 h-3 mr-1.5 animate-spin" />
                        <Download v-else class="w-3 h-3 mr-1.5" />
                        {{ importing === recording.id ? 'Importing...' : 'Import' }}
                    </Button>

                    <Button
                        size="sm"
                        variant="ghost"
                        class="shrink-0 text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-xl h-9 w-9 p-0"
                        @click="confirmDismissRecording(recording)"
                    >
                        <Trash2 class="w-4 h-4" />
                    </Button>
                </template>
            </div>
        </div>
    </section>

    <ConfirmDeleteModal
        :open="isDismissRecordingOpen"
        :title="`Remove &quot;${recordingToDismiss?.title}&quot;?`"
        description="This recording will be hidden from the available list and won't be importable into this project."
        :loading="dismissingRecording"
        @close="closeDismissRecording"
        @confirm="handleDismissRecording"
    />
</template>
