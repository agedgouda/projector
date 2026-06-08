<script setup lang="ts">
import { computed } from 'vue';
import { AlertCircle, Download, Loader2, Trash2, Video, FileText } from 'lucide-vue-next';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import { useTranscriptActions } from '@/composables/transcripts/useTranscriptActions';
import { formatDate } from '@/lib/utils';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';

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

        <div v-else class="grid gap-0.5">
            <div
                v-for="recording in pendingRecordings"
                :key="recording.id"
                :class="['group flex items-center gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]"
            >
                <div class="w-4 h-4 flex items-center justify-center shrink-0 text-slate-400">
                    <Video class="w-3.5 h-3.5" />
                </div>

                <div class="flex-1 flex items-center gap-2.5 min-w-0">
                    <span class="font-semibold text-[13px] text-slate-900 dark:text-slate-100 truncate">{{ recording.title }}</span>
                    <span class="text-[11px] text-slate-400 shrink-0">
                        {{ formatDate(recording.started_at) }}
                        <template v-if="recording.duration_minutes">· {{ recording.duration_minutes }} min</template>
                    </span>
                </div>

                <template v-if="canManage">
                    <Button
                        size="sm"
                        :disabled="importing === recording.id || importingAsRequirements === recording.id"
                        class="shrink-0 bg-projector-primary-600 hover:bg-projector-primary-700 text-white rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest"
                        @click="importRecording(recording)"
                    >
                        <Loader2 v-if="importing === recording.id" class="w-3 h-3 mr-1.5 animate-spin" />
                        <Download v-else class="w-3 h-3 mr-1.5" />
                        {{ importing === recording.id ? 'Importing...' : 'Import' }}
                    </Button>

                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="importing === recording.id || importingAsRequirements === recording.id"
                        class="shrink-0 rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest text-slate-600 dark:text-slate-300"
                        @click="importRecording(recording, 'requirements')"
                    >
                        <Loader2 v-if="importingAsRequirements === recording.id" class="w-3 h-3 mr-1.5 animate-spin" />
                        <FileText v-else class="w-3 h-3 mr-1.5" />
                        {{ importingAsRequirements === recording.id ? 'Importing...' : 'Requirements' }}
                    </Button>

                    <button
                        type="button"
                        class="h-8 w-8 flex items-center justify-center rounded-md text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 opacity-0 group-hover:opacity-100 transition-opacity shrink-0"
                        title="Dismiss recording"
                        @click="confirmDismissRecording(recording)"
                    >
                        <Trash2 class="w-3.5 h-3.5" />
                    </button>
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
