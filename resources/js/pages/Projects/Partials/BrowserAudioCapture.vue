<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import * as tus from 'tus-js-client';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Download, Mic, RotateCcw, Square } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { BANNER_PRIORITY, useGlobalProcessingBanner } from '@/composables/useGlobalProcessingBanner';
import tusRoutes from '@/routes/projects/browser-recordings/tus';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { goToLogin } from '@/lib/sessionExpiry';

const props = defineProps<{
    projectId: string;
    canManage: boolean;
}>();

type Phase = 'idle' | 'requesting-permission' | 'recording' | 'uploading' | 'error' | 'save-failed';

// getDisplayMedia audio capture is Chromium-only in practice (Safari doesn't reliably expose
// an audio track, older browsers don't have the API at all) — checked once up front so the
// rest of the component never has to special-case a missing capability mid-flow.
const isUnsupported = computed(() => typeof navigator === 'undefined' || !navigator.mediaDevices?.getDisplayMedia);

const phase = ref<Phase>('idle');
const errorMessage = ref('');
const elapsedSeconds = ref(0);

// A chunk this small is durable on the server within a couple of seconds of being produced, so
// this is the real bound on "how much can closing the tab lose" — not the whole recording.
const CHUNK_INTERVAL_MS = 20_000;

let mediaRecorder: MediaRecorder | null = null;
// One entry per MediaRecorder interval, in recording order — each resolves to that chunk's
// finished tus upload URL once tus-js-client's own automatic retry/resume gets it there. Built
// with input order preserved regardless of completion order (see stopCapture()'s Promise.all).
let chunkUploads: Promise<string>[] = [];
// The recorded audio itself, one Blob per interval, kept in memory until the recording is
// saved — so a chunk whose upload failed can be sent again, and a recording the server can't
// be reached for can still be downloaded instead of lost.
let recordedBlobs: Blob[] = [];
let recordedAt: string | null = null;
// captureStream carries the video track getDisplayMedia forces on us (stopped immediately,
// never recorded); audioStream is the video-free stream actually fed to MediaRecorder.
let captureStream: MediaStream | null = null;
let audioStream: MediaStream | null = null;
let timerInterval: ReturnType<typeof window.setInterval> | undefined;
let progressTimer: ReturnType<typeof window.setInterval> | undefined;

// The single shared "AI Sync Active" banner (AppLayout.vue's one <AiProcessingHeader>) instead
// of a page-local spinner — same pattern as every other async operation in the app. Progress is
// a fake creep, same as StatusMeetings/Index.vue's own copy of this pattern: with chunks
// already uploading continuously throughout the recording, this phase only covers the brief
// "let the last chunk finish, then concatenate" window, too short for real progress to be
// worth wiring up.
const { setBanner, clearBanner } = useGlobalProcessingBanner();
const uploadProgress = ref(0);
const BANNER_KEY = 'browser-capture-upload';

watch(() => phase.value === 'uploading', (isUploading) => {
    if (isUploading) {
        uploadProgress.value = 5;
        progressTimer = window.setInterval(() => {
            if (uploadProgress.value < 80) uploadProgress.value += 2;
        }, 800);
    } else {
        if (progressTimer !== undefined) window.clearInterval(progressTimer);
        progressTimer = undefined;
        if (uploadProgress.value > 0) {
            uploadProgress.value = 100;
            window.setTimeout(() => { uploadProgress.value = 0; }, 600);
        }
    }
});

watch([() => phase.value === 'uploading', uploadProgress], ([isUploading, progress]) => {
    if (isUploading) {
        setBanner(BANNER_KEY, {
            title: 'AI Sync Active',
            message: 'Finishing upload…',
            progress,
            priority: BANNER_PRIORITY.AI_PROCESSING,
        });
    } else {
        clearBanner(BANNER_KEY);
    }
});

const formattedElapsed = computed(() => {
    const m = Math.floor(elapsedSeconds.value / 60).toString().padStart(2, '0');
    const s = (elapsedSeconds.value % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
});

// Waits between automatic retries of Stop when the connection drops or the server errors.
const FINISH_RETRY_DELAYS_MS = [1_000, 3_000, 8_000];

const pickMimeType = (): string | undefined => {
    if (typeof MediaRecorder === 'undefined') return undefined;

    return ['audio/webm', 'audio/mp4', 'audio/wav'].find(type => MediaRecorder.isTypeSupported(type));
};

// axios elsewhere in this app reads the XSRF-TOKEN cookie automatically; tus-js-client doesn't,
// so every tus request needs this passed through its own `headers` option by hand.
const csrfHeader = (): Record<string, string> => {
    const cookie = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='));
    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.split('=')[1]) } : {};
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

// Warns against closing the tab mid-capture — chunks already uploaded are safe (see
// CHUNK_INTERVAL_MS above), but the recording itself only gets transcribed once Stop actually
// runs the concatenation step below; closing early leaves those chunks orphaned (cleaned up
// automatically after a day — see App\Console\Commands\PruneAbandonedTusUploads) rather than
// turned into a note.
const beforeUnloadHandler = (event: BeforeUnloadEvent) => {
    event.preventDefault();
    event.returnValue = '';
};

const guardUnload = () => window.addEventListener('beforeunload', beforeUnloadHandler);
const unguardUnload = () => window.removeEventListener('beforeunload', beforeUnloadHandler);

// Uploads one MediaRecorder interval as its own small, complete tus upload — tus-js-client
// handles retrying a dropped connection and resuming from the correct byte offset on its own,
// which is the entire reason to use it here instead of a plain POST per chunk.
const uploadChunk = (blob: Blob, index: number): Promise<string> => new Promise((resolve, reject) => {
    const upload = new tus.Upload(blob, {
        endpoint: tusRoutes.create({ project: props.projectId }).url,
        headers: csrfHeader(),
        metadata: {
            filename: `chunk-${index}.webm`,
            filetype: blob.type || 'audio/webm',
            recorded_at: recordedAt ?? new Date().toISOString(),
        },
        retryDelays: [0, 1000, 3000, 5000, 10000],
        onSuccess: () => {
            if (upload.url) resolve(upload.url);
            else reject(new Error('Upload finished without a URL.'));
        },
        onError: (error) => reject(error),
    });
    upload.start();
});

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

    chunkUploads = [];
    recordedBlobs = [];
    recordedAt = new Date().toISOString();
    const mimeType = pickMimeType();
    mediaRecorder = mimeType ? new MediaRecorder(audioStream, { mimeType }) : new MediaRecorder(audioStream);

    let chunkIndex = 0;
    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
            recordedBlobs.push(event.data);
            chunkUploads.push(uploadChunk(event.data, chunkIndex++));
        }
    };

    mediaRecorder.onstop = () => {
        stopStreams();
        clearTimer();
        void finalize();
    };

    mediaRecorder.start(CHUNK_INTERVAL_MS);
    elapsedSeconds.value = 0;
    phase.value = 'recording';
    guardUnload();
    timerInterval = window.setInterval(() => { elapsedSeconds.value += 1; }, 1000);
};

const stopCapture = () => {
    mediaRecorder?.stop();
};

const chunkIdFromUrl = (url: string): string => url.split('/').pop() ?? '';

// Every chunk's upload id in recording order. A chunk whose upload gave up mid-recording gets
// one more try from the copy still in memory; if that fails too, this throws and Try Again
// picks up from there (chunks that already made it are never re-sent).
const collectChunkIds = async (): Promise<string[]> => {
    const results = await Promise.allSettled(chunkUploads);
    const ids: string[] = [];

    for (const [index, result] of results.entries()) {
        if (result.status === 'rejected') {
            chunkUploads[index] = uploadChunk(recordedBlobs[index], index);
        }
        ids.push(chunkIdFromUrl(await chunkUploads[index]));
    }

    return ids;
};

// Stop, sent as a JSON body: a long recording's chunk list is far too big for a request
// header, which is what made recordings over about half an hour fail. Retried on a dropped
// connection or a server error — safe, since the server hands back the same recording if an
// earlier attempt actually got through.
const sendFinish = async (ids: string[]): Promise<Response> => {
    for (let attempt = 0; ; attempt++) {
        try {
            const response = await fetch(tusRoutes.finish({ project: props.projectId }).url, {
                method: 'POST',
                headers: {
                    ...csrfHeader(),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ upload_ids: ids }),
            });

            if (response.status < 500 || attempt >= FINISH_RETRY_DELAYS_MS.length) return response;
        } catch (error) {
            if (attempt >= FINISH_RETRY_DELAYS_MS.length) throw error;
        }

        await new Promise(resolve => window.setTimeout(resolve, FINISH_RETRY_DELAYS_MS[attempt]));
    }
};

const SAVE_FAILED_MESSAGE = "We couldn't finish saving this recording, but your audio is safe. Try again, or download a copy to keep.";

const finalize = async () => {
    phase.value = 'uploading';
    errorMessage.value = '';

    if (recordedBlobs.length === 0) {
        unguardUnload();
        phase.value = 'error';
        errorMessage.value = 'No audio was captured. Please try again.';
        return;
    }

    try {
        const response = await sendFinish(await collectChunkIds());

        // Fetch-native counterparts of sessionExpiry.ts's axios-based helpers, which expect an
        // AxiosResponse/AxiosError shape this raw fetch call doesn't produce. The audio is still
        // in memory and already on the server, so signing back in doesn't lose it.
        const contentType = response.headers.get('content-type');
        if (response.status === 401 || response.status === 419 || (response.ok && !contentType?.includes('application/json'))) {
            unguardUnload();
            goToLogin();
            return;
        }

        const body = await response.json().catch(() => null);

        if (!response.ok || !body?.recording) {
            phase.value = 'save-failed';
            errorMessage.value = body?.message ? `${body.message} Your audio is safe. Try again, or download a copy to keep.` : SAVE_FAILED_MESSAGE;
            return;
        }

        unguardUnload();

        // Same as every other transcript source (Google Doc, file, provider-imported
        // recording) — never leave the user on the raw transcript, go straight to the
        // Meeting Notes document it's about to generate (see RecordingIntakeService::
        // startRecording()). Falls back to the transcript itself only when no single-output
        // template is configured, matching IntakeImportService::import()'s own fallback.
        const targetId = body.recording.notes_id ?? body.recording.id;
        router.visit(projectDocumentsRoutes.show({ project: props.projectId, document: targetId }).url);
    } catch {
        phase.value = 'save-failed';
        errorMessage.value = SAVE_FAILED_MESSAGE;
    }
};

const downloadRecording = () => {
    const type = recordedBlobs[0]?.type || 'audio/webm';
    const extension = type.includes('mp4') ? 'm4a' : type.includes('wav') ? 'wav' : 'webm';
    const url = URL.createObjectURL(new Blob(recordedBlobs, { type }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `recording-${(recordedAt ?? new Date().toISOString()).slice(0, 16).replace(/[:T]/g, '-')}.${extension}`;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
};

const reset = () => {
    phase.value = 'idle';
    errorMessage.value = '';
};

onBeforeUnmount(() => {
    clearTimer();
    stopStreams();
    unguardUnload();
    // Success leaves phase at 'uploading' right up until the router.visit() redirect unmounts
    // this component — the watch above never sees it leave that state, so the banner and its
    // creep timer need clearing here too, not just on the error path.
    if (progressTimer !== undefined) window.clearInterval(progressTimer);
    clearBanner(BANNER_KEY);
});
</script>

<template>
    <div v-if="canManage" class="rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900/40 p-6">
        <h2 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Capture Live Meeting Audio</h2>
        <p class="mt-1 max-w-xl text-sm text-slate-500 dark:text-slate-400">
            Share this browser tab (or, on Windows, your whole screen) while a call is running, and it's transcribed
            automatically once you stop. On a Mac this only works for calls running in a browser tab — not a native
            desktop app like Zoom or Slack. Audio uploads continuously as you record, so a dropped connection or a
            closed tab only risks the last few seconds — if the tab closes before you stop, what was recorded is saved
            as a "Recovered recording" within a couple of hours.
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

            <div v-else-if="phase === 'save-failed'" class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/20">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p class="text-sm text-amber-700 dark:text-amber-400">{{ errorMessage }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button
                            class="h-9 rounded-xl bg-projector-primary-600 px-4 font-bold text-white hover:bg-projector-primary-700"
                            @click="finalize"
                        >
                            <RotateCcw class="mr-2 h-4 w-4" />
                            Try Again
                        </Button>
                        <Button variant="outline" class="h-9 rounded-xl px-4 font-bold" @click="downloadRecording">
                            <Download class="mr-2 h-4 w-4" />
                            Download Audio
                        </Button>
                    </div>
                </div>
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
