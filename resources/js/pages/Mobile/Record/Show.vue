<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, onBeforeUnmount } from 'vue';
import axios from 'axios';
import MobileLayout from '@/layouts/MobileLayout.vue';
import { Mic, Square, RefreshCw, AlertTriangle } from 'lucide-vue-next';
import mobileRecordRoutes from '@/routes/mobile/record';
import mobileDocumentRoutes from '@/routes/mobile/documents';
import { redirectIfLoggedOut, redirectIfSessionExpiredError } from '@/lib/sessionExpiry';

const props = defineProps<{
    project: { id: string; name: string };
}>();

type Phase = 'idle' | 'requesting-permission' | 'recording' | 'uploading' | 'processing' | 'error';

const phase = ref<Phase>('idle');
const errorMessage = ref('');
const elapsedSeconds = ref(0);

let mediaRecorder: MediaRecorder | null = null;
let chunks: BlobPart[] = [];
let stream: MediaStream | null = null;
let timerInterval: ReturnType<typeof window.setInterval> | undefined;
let pollInterval: ReturnType<typeof window.setInterval> | undefined;

const formattedElapsed = computed(() => {
    const m = Math.floor(elapsedSeconds.value / 60).toString().padStart(2, '0');
    const s = (elapsedSeconds.value % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
});

/** iOS/Safari's WKWebView supports audio/mp4, not webm; Chrome/Android is the reverse. */
const pickMimeType = (): string | undefined => {
    if (typeof MediaRecorder === 'undefined') return undefined;

    return ['audio/mp4', 'audio/webm', 'audio/aac', 'audio/wav'].find(type => MediaRecorder.isTypeSupported(type));
};

const extensionFor = (mimeType: string): string => {
    if (mimeType.includes('mp4')) return 'm4a';
    if (mimeType.includes('webm')) return 'webm';
    if (mimeType.includes('aac')) return 'aac';
    if (mimeType.includes('wav')) return 'wav';
    return 'webm';
};

const stopStream = () => {
    stream?.getTracks().forEach(track => track.stop());
    stream = null;
};

const clearTimer = () => {
    if (timerInterval !== undefined) window.clearInterval(timerInterval);
    timerInterval = undefined;
};

const clearPoll = () => {
    if (pollInterval !== undefined) window.clearInterval(pollInterval);
    pollInterval = undefined;
};

const startRecording = async () => {
    errorMessage.value = '';
    phase.value = 'requesting-permission';

    // navigator.mediaDevices only exists in a secure context (HTTPS, or the Capacitor
    // WebView's own local scheme) — accessing getUserMedia off an undefined mediaDevices
    // would throw a TypeError rather than a rejectable promise, so guard it explicitly.
    if (!navigator.mediaDevices?.getUserMedia) {
        phase.value = 'error';
        errorMessage.value = 'Recording requires a secure connection, which this page is missing.';
        return;
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
        phase.value = 'error';
        errorMessage.value = "Microphone access was denied. Enable it in this app's settings to record.";
        return;
    }

    chunks = [];
    const mimeType = pickMimeType();
    mediaRecorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);

    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunks.push(event.data);
    };

    mediaRecorder.onstop = () => {
        stopStream();
        clearTimer();
        void upload(new Blob(chunks, { type: mediaRecorder?.mimeType || 'audio/webm' }));
    };

    mediaRecorder.start();
    elapsedSeconds.value = 0;
    phase.value = 'recording';
    timerInterval = window.setInterval(() => { elapsedSeconds.value += 1; }, 1000);
};

const stopRecording = () => {
    mediaRecorder?.stop();
};

const upload = async (blob: Blob) => {
    phase.value = 'uploading';

    const formData = new FormData();
    formData.append('audio', blob, `recording.${extensionFor(blob.type)}`);
    formData.append('recorded_at', new Date().toISOString());

    try {
        const response = await axios.post(mobileRecordRoutes.store(props.project.id).url, formData);
        if (redirectIfLoggedOut(response)) return;

        phase.value = 'processing';
        startPolling(response.data.recording.id as string);
    } catch (error) {
        if (redirectIfSessionExpiredError(error)) return;

        phase.value = 'error';
        errorMessage.value = 'Failed to upload the recording. Please try again.';
    }
};

const startPolling = (documentId: string) => {
    clearPoll();

    pollInterval = window.setInterval(async () => {
        try {
            const response = await axios.get(
                mobileRecordRoutes.status({ project: props.project.id, document: documentId }).url,
            );
            if (redirectIfLoggedOut(response)) { clearPoll(); return; }

            const status = response.data.recording.status as string;

            if (status === 'processed') {
                clearPoll();
                router.visit(mobileDocumentRoutes.show({ project: props.project.id, document: documentId }).url);
            } else if (status === 'failed') {
                clearPoll();
                phase.value = 'error';
                errorMessage.value = "Transcription didn't complete for this recording.";
            }
        } catch (error) {
            if (redirectIfSessionExpiredError(error)) clearPoll();
            // Otherwise keep polling — a single transient blip shouldn't end the whole flow.
        }
    }, 4000);
};

const reset = () => {
    phase.value = 'idle';
    errorMessage.value = '';
};

onBeforeUnmount(() => {
    clearTimer();
    clearPoll();
    stopStream();
});
</script>

<template>
    <Head title="Record a Meeting" />

    <MobileLayout title="Record a Meeting" :back-href="mobileRecordRoutes.index().url">
        <div class="p-4 h-full flex flex-col items-center justify-center gap-6 text-center">
            <div class="space-y-1">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Recording for</p>
                <p class="text-lg font-bold text-slate-900 dark:text-white">{{ project.name }}</p>
            </div>

            <template v-if="phase === 'idle' || phase === 'requesting-permission'">
                <button
                    type="button"
                    :disabled="phase === 'requesting-permission'"
                    class="h-24 w-24 rounded-full bg-projector-primary-600 flex items-center justify-center text-white active:opacity-80 disabled:opacity-60"
                    @click="startRecording"
                >
                    <Mic class="w-10 h-10" />
                </button>
                <p class="text-[13px] text-slate-400">
                    {{ phase === 'requesting-permission' ? 'Requesting microphone access…' : 'Tap to start recording' }}
                </p>
            </template>

            <template v-else-if="phase === 'recording'">
                <button
                    type="button"
                    class="h-24 w-24 rounded-full bg-red-600 flex items-center justify-center text-white active:opacity-80"
                    @click="stopRecording"
                >
                    <Square class="w-9 h-9" fill="currentColor" />
                </button>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                    <p class="text-2xl font-black tabular-nums text-slate-900 dark:text-white">{{ formattedElapsed }}</p>
                </div>
                <p class="text-[13px] text-slate-400">Tap to stop recording</p>
            </template>

            <template v-else-if="phase === 'uploading'">
                <div class="h-24 w-24 rounded-full bg-slate-200 dark:bg-white/10 flex items-center justify-center text-slate-500 dark:text-slate-400">
                    <RefreshCw class="w-9 h-9 animate-spin" />
                </div>
                <p class="text-[13px] font-bold text-slate-500">Uploading recording…</p>
            </template>

            <template v-else-if="phase === 'processing'">
                <div class="h-24 w-24 rounded-full bg-slate-200 dark:bg-white/10 flex items-center justify-center text-slate-500 dark:text-slate-400">
                    <RefreshCw class="w-9 h-9 animate-spin" />
                </div>
                <div class="max-w-xs space-y-1">
                    <p class="font-bold text-slate-700 dark:text-slate-300">Transcribing…</p>
                    <p class="text-[13px] text-slate-400">This can take a few minutes for a longer recording. You can leave this screen — the note will be ready in the project once it's done.</p>
                </div>
            </template>

            <template v-else-if="phase === 'error'">
                <div class="h-24 w-24 rounded-full bg-amber-100 dark:bg-amber-950/40 flex items-center justify-center text-amber-600 dark:text-amber-400">
                    <AlertTriangle class="w-9 h-9" />
                </div>
                <div class="max-w-xs space-y-3">
                    <p class="text-[13px] text-slate-500">{{ errorMessage }}</p>
                    <button
                        type="button"
                        class="text-[11px] font-black uppercase tracking-widest text-projector-primary-600 dark:text-projector-primary-400"
                        @click="reset"
                    >
                        Try Again
                    </button>
                </div>
            </template>
        </div>
    </MobileLayout>
</template>
