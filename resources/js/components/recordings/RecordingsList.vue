<script setup lang="ts">
import { computed, reactive } from 'vue';
import { AlertCircle, Loader2, Trash2, Video } from 'lucide-vue-next';
import AiInstructionsPopover from '@/components/transcripts/AiInstructionsPopover.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/utils';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';

const props = defineProps<{
    recordings: Recording[];
    // Caller-computed union of every reason a recording shouldn't be offered again (already
    // imported here, imported elsewhere, etc.) — keeps this component itself agnostic to
    // what "already spoken for" means in a given context.
    excludedIds: string[];
    canManage: boolean;
    providerError?: string | null;
    actions: RecordingAction[];
    onDismiss?: (recording: Recording) => void;
}>();

const pendingRecordings = computed(() =>
    props.recordings.filter((r) => !props.excludedIds.includes(r.id))
);

// Optional per-recording AI instructions, entered via the popover below — keyed by
// recording id so multiple pending recordings can each hold their own text at once.
const customPrompts = reactive<Record<string, string>>({});
</script>

<template>
    <div
        v-if="providerError"
        class="flex items-start gap-4 rounded-2xl border border-red-200 bg-red-50 p-6 dark:border-red-800 dark:bg-red-950/20"
    >
        <AlertCircle class="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
        <div>
            <p class="text-sm font-bold text-red-800 dark:text-red-300">
                Failed to connect to meeting provider
            </p>
            <p class="mt-1 font-mono text-sm break-all text-red-700 dark:text-red-400">
                {{ providerError }}
            </p>
        </div>
    </div>

    <section v-else>
        <div
            v-if="pendingRecordings.length === 0"
            class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 bg-gray-50/50 py-16 dark:border-gray-700 dark:bg-gray-900/30"
        >
            <Video class="mb-3 h-8 w-8 text-gray-300" />
            <p class="text-sm font-bold text-gray-500">No new recordings found</p>
            <p class="mt-1 text-xs text-gray-400">
                All recent recordings have already been imported, or none exist yet.
            </p>
        </div>

        <div v-else class="grid gap-0.5">
            <div
                v-for="recording in pendingRecordings"
                :key="recording.id"
                :class="['group relative flex items-center gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]"
            >
                <div class="flex h-4 w-4 shrink-0 items-center justify-center text-slate-400">
                    <Video class="h-3.5 w-3.5" />
                </div>

                <div class="flex min-w-0 flex-1 items-center gap-2.5">
                    <span class="truncate text-[13px] font-semibold text-slate-900 dark:text-slate-100">
                        {{ recording.title }}
                    </span>
                    <span class="shrink-0 text-[11px] text-slate-400">
                        {{ formatDate(recording.started_at) }}
                        <template v-if="recording.duration_minutes">· {{ recording.duration_minutes }} min</template>
                    </span>
                </div>

                <template v-if="canManage">
                    <AiInstructionsPopover v-model="customPrompts[recording.id]" />

                    <Button
                        v-for="action in actions"
                        :key="action.label"
                        size="sm"
                        :variant="action.variant === 'outline' ? 'outline' : undefined"
                        :disabled="(action.disabled ?? action.loading)(recording)"
                        :class="[
                            'w-28 shrink-0 rounded-md px-3 h-8 text-[10px] font-black uppercase tracking-widest',
                            action.variant === 'outline'
                                ? 'text-slate-600 dark:text-slate-300'
                                : 'bg-projector-primary-600 hover:bg-projector-primary-700 text-white',
                        ]"
                        @click="action.onClick(recording, customPrompts[recording.id] ?? '')"
                    >
                        <Loader2 v-if="action.loading(recording)" class="w-3 h-3 mr-1.5 animate-spin" />
                        <component v-else :is="action.icon" class="w-3 h-3 mr-1.5" />
                        {{ action.loading(recording) ? 'Importing...' : action.label }}
                    </Button>

                    <button
                        v-if="onDismiss"
                        type="button"
                        class="absolute top-1/2 left-full ml-1 flex h-8 w-8 shrink-0 -translate-y-1/2 items-center justify-center rounded-md text-slate-300 opacity-0 transition-opacity group-hover:opacity-100 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-950/30"
                        title="Dismiss recording"
                        @click="onDismiss(recording)"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                    </button>
                </template>
            </div>
        </div>
    </section>
</template>
