<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, Mic, RefreshCw, Square } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import browserRecordingRoutes from '@/routes/projects/browser-recordings';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { redirectIfLoggedOut, redirectIfSessionExpiredError } from '@/lib/sessionExpiry';

const props = defineProps<{
    projectId: string;
    canManage: boolean;
}>();

type Phase = 'idle' | 'requesting-permission' | 'recording' | 'uploading' | 'processing' | 'done' | 'error';

// getDisplayMedia audio capture is Chromium-only in practice (Safari doesn't reliably expose
// an audio track, older browsers don't have the API at all) — checked once up front so the
// rest of the component never has to special-case a missing capability mid-flow.
const isUnsupported = computed(() => typeof navigator === 'undefined' || !navigator.mediaDevices?.getDisplayMedia);

const phase = ref<Phase>('idle');
const errorMessage = ref('');
const elapsedSeconds = ref(0);
const documentId = ref<string | null>(null);

let mediaRecorder: MediaRecorder | null = null;
let chunks: BlobPart[] = [];
// captureStream carries the video track getDisplayMedia forces on us (stopped immediately,
// never recorded); audioStream is the video-free stream actually fed to MediaRecorder.
let captureStream: MediaStream | null = null;
let audioStream: MediaStream | null = null;
let timerInterval: ReturnType<typeof window.setInterval> | undefined;
let pollInterval: ReturnType<typeof window.setInterval> | undefined;

const formattedElapsed = computed(() => {
    const m = Math.floor(elapsedSeconds.value / 60).toString().padStart(2, '0');
    const s = (elapsedSeconds.value % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
});

const documentUrl = computed(() =>
    documentId.value ? projectDocumentsRoutes.show({ project: props.projectId, document: documentId.value }).url : null,
);

const pickMimeType = (): string | undefined => {
    if (typeof MediaRecorder === 'undefined') return undefined;

    return ['audio/webm', 'audio/mp4', 'audio/wav'].find(type => MediaRecorder.isTypeSupported(type));
};

const extensionFor = (mimeType: string): string => {
    if (mimeType.includes('webm')) return 'webm';
    if (mimeType.includes('mp4')) return 'm4a';
    if (mimeType.includes('wav')) return 'wav';
    return 'webm';
};

const stopStreams = () => {
    captureStream?.getTracks().forEach(track => track.stop());
    audioStream?.getTracks().forEach(track => track.stop());
    captureStream = null;
    audioStream = null;
};

const clearTimer = () => {
    if (timerInterval !== undefined) window.clearInterval(timerInterval);
    timerInterval = undefined;
};

const clearPoll = () => {
    if (pollInterval !== undefined) window.clearInterval(pollInterval);
    pollInterval = undefined;
};

// Warns against closing the tab mid-capture or mid-upload — there's no background recording
// or resumable upload here, so losing the tab loses the whole recording (same accepted
// limitation as the mobile flow).
const beforeUnloadHandler = (event: BeforeUnloadEvent) => {
    event.preventDefault();
    event.returnValue = '';
};

const guardUnload = () => window.addEventListener('beforeunload', beforeUnloadHandler);
const unguardUnload = () => window.removeEventListener('beforeunload', beforeUnloadHandler);

const startCapture = async () => {
    errorMessage.value = '';
    phase.value = 'requesting-permission';

    try {
        // video: true is required to get the browser's share picker at all, even though we
        // only want the audio track — discarded immediately below.
        captureStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true });
    } catch {
        // Most commonly the user just dismissed the picker — not worth surfacing as an error.
        phase.value = 'idle';
        return;
    }

    captureStream.getVideoTracks().forEach(track => track.stop());

    const audioTracks = captureStream.getAudioTracks();
    if (audioTracks.length === 0) {
        stopStreams();
        phase.value = 'error';
        errorMessage.value = 'No audio was shared. When the picker opens again, check "Share tab audio" (or "Share system audio") before starting.';
        return;
    }

    audioStream = new MediaStream(audioTracks);
    // Covers the browser/OS's own "Stop sharing" control, not just our in-app Stop button.
    audioTracks[0].onended = () => stopCapture();

    chunks = [];
    const mimeType = pickMimeType();
    mediaRecorder = mimeType ? new MediaRecorder(audioStream, { mimeType }) : new MediaRecorder(audioStream);

    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunks.push(event.data);
    };

    mediaRecorder.onstop = () => {
        stopStreams();
        clearTimer();
        void upload(new Blob(chunks, { type: mediaRecorder?.mimeType || 'audio/webm' }));
    };

    mediaRecorder.start();
    elapsedSeconds.value = 0;
    phase.value = 'recording';
    guardUnload();
    timerInterval = window.setInterval(() => { elapsedSeconds.value += 1; }, 1000);
};

const stopCapture = () => {
    mediaRecorder?.stop();
};

const upload = async (blob: Blob) => {
    phase.value = 'uploading';

    const formData = new FormData();
    formData.append('audio', blob, `recording.${extensionFor(blob.type)}`);
    formData.append('recorded_at', new Date().toISOString());

    try {
        const response = await axios.post(browserRecordingRoutes.store(props.projectId).url, formData);
        unguardUnload();
        if (redirectIfLoggedOut(response)) return;

        phase.value = 'processing';
        startPolling(response.data.recording.id as string);
    } catch (error) {
        unguardUnload();
        if (redirectIfSessionExpiredError(error)) return;

        phase.value = 'error';
        errorMessage.value = 'Failed to upload the recording. Please try again.';
    }
};

const startPolling = (newDocumentId: string) => {
    clearPoll();

    pollInterval = window.setInterval(async () => {
        try {
            const response = await axios.get(
                browserRecordingRoutes.status({ project: props.projectId, document: newDocumentId }).url,
            );
            if (redirectIfLoggedOut(response)) { clearPoll(); return; }

            const status = response.data.recording.status as string;

            if (status === 'processed') {
                clearPoll();
                documentId.value = newDocumentId;
                phase.value = 'done';
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
    documentId.value = null;
};

onBeforeUnmount(() => {
    clearTimer();
    clearPoll();
    stopStreams();
    unguardUnload();
});
</script>

<template>
    <div v-if="canManage" class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900/40 p-6">
        <h2 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Capture Live Meeting Audio</h2>
        <p class="mt-1 max-w-xl text-sm text-slate-500 dark:text-slate-400">
            Share this browser tab (or, on Windows, your whole screen) while a call is running, and it's transcribed
            automatically once you stop. On a Mac this only works for calls running in a browser tab — not a native
            desktop app like Zoom or Slack.
        </p>

        <p v-if="isUnsupported" class="mt-4 text-sm text-slate-500 dark:text-slate-400">
            Your browser doesn't support this — try the latest Chrome or Edge.
        </p>

        <template v-else>
            <div v-if="phase === 'idle' || phase === 'requesting-permission'" class="mt-4">
                <Button
                    :disabled="phase === 'requesting-permission'"
                    class="h-10 rounded-xl bg-projector-primary-600 px-5 font-bold text-white hover:bg-projector-primary-700"
                    @click="startCapture"
                >
                    <Mic class="mr-2 h-4 w-4" />
                    {{ phase === 'requesting-permission' ? 'Waiting for share…' : 'Start Capture' }}
                </Button>
            </div>

            <div v-else-if="phase === 'recording'" class="mt-4 flex items-center gap-4">
                <Button
                    class="h-10 rounded-xl bg-red-600 px-5 font-bold text-white hover:bg-red-700"
                    @click="stopCapture"
                >
                    <Square class="mr-2 h-4 w-4" fill="currentColor" />
                    Stop Capture
                </Button>
                <div class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full bg-red-500 animate-pulse"></span>
                    <span class="text-lg font-black tabular-nums text-slate-900 dark:text-white">{{ formattedElapsed }}</span>
                </div>
            </div>

            <div v-else-if="phase === 'uploading' || phase === 'processing'" class="mt-4 flex items-center gap-3 text-slate-500 dark:text-slate-400">
                <RefreshCw class="h-5 w-5 animate-spin" />
                <span class="text-sm font-bold">
                    {{ phase === 'uploading' ? 'Uploading recording…' : "Transcribing… you can navigate away, it'll show up here once it's ready." }}
                </span>
            </div>

            <div v-else-if="phase === 'done'" class="mt-4 flex items-center gap-4">
                <p class="text-sm font-bold text-emerald-600 dark:text-emerald-400">Transcript ready.</p>
                <Link v-if="documentUrl" :href="documentUrl" class="text-sm font-bold text-projector-primary-600 hover:underline dark:text-projector-primary-400">
                    View note
                </Link>
                <button type="button" class="text-[11px] font-black uppercase tracking-widest text-slate-400" @click="reset">
                    Capture Another
                </button>
            </div>

            <div v-else-if="phase === 'error'" class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/20">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p class="text-sm text-amber-700 dark:text-amber-400">{{ errorMessage }}</p>
                    <button
                        type="button"
                        class="mt-2 text-[11px] font-black uppercase tracking-widest text-projector-primary-600 dark:text-projector-primary-400"
                        @click="reset"
                    >
                        Try Again
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
