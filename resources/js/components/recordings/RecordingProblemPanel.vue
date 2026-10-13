<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Download, RotateCcw } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import recordingRoutes from '@/routes/projects/documents/recording';

export interface RecordingProblem {
    document_id: string;
    reason: string;
    has_audio: boolean;
    can_retry: boolean;
}

// Shown on a recording's transcript page and its Meeting Notes page when transcription failed
// or stalled (see RecordingRecoveryService::problemFor()) — only to the people who can act on
// it, so the page itself decides whether this renders at all.
const props = defineProps<{
    projectId: string;
    problem: RecordingProblem;
}>();

const isRetrying = ref(false);

const args = () => ({ project: props.projectId, document: props.problem.document_id });

const retry = () => {
    isRetrying.value = true;
    router.post(recordingRoutes.retry.url(args()), {}, {
        preserveScroll: true,
        onFinish: () => { isRetrying.value = false; },
    });
};
</script>

<template>
    <div class="mb-8 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/20">
        <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
        <div class="min-w-0">
            <p class="text-sm font-bold text-amber-800 dark:text-amber-300">This recording couldn't be transcribed</p>
            <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">{{ problem.reason }}</p>
            <p v-if="!problem.has_audio" class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                No audio was saved for this recording, so there's nothing to try again with.
            </p>
            <div v-if="problem.has_audio" class="mt-3 flex flex-wrap gap-2">
                <Button
                    v-if="problem.can_retry"
                    :disabled="isRetrying"
                    class="h-9 rounded-xl bg-projector-primary-600 px-4 font-bold text-white hover:bg-projector-primary-700"
                    @click="retry"
                >
                    <RotateCcw class="mr-2 h-4 w-4" :class="{ 'animate-spin': isRetrying }" />
                    {{ isRetrying ? 'Trying Again…' : 'Try Again' }}
                </Button>
                <Button variant="outline" class="h-9 rounded-xl px-4 font-bold" as-child>
                    <a :href="recordingRoutes.download.url(args())">
                        <Download class="mr-2 h-4 w-4" />
                        Download Audio
                    </a>
                </Button>
            </div>
        </div>
    </div>
</template>
