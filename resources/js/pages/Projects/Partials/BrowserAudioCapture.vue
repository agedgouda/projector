<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import * as tus from 'tus-js-client';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Mic, Square } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { BANNER_PRIORITY, useGlobalProcessingBanner } from '@/composables/useGlobalProcessingBanner';
import tusRoutes from '@/routes/projects/browser-recordings/tus';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { goToLogin } from '@/lib/sessionExpiry';

const props = defineProps<{
    projectId: string;
    canManage: boolean;
}>();

type Phase = 'idle' | 'requesting-permission' | 'recording' | 'uploading' | 'error';

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
    recordedAt = new Date().toISOString();
    const mimeType = pickMimeType();
    mediaRecorder = mimeType ? new MediaRecorder(audioStream, { mimeType }) : new MediaRecorder(audioStream);

    let chunkIndex = 0;
    mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunkUploads.push(uploadChunk(event.data, chunkIndex++));
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

const finalize = async () => {
    phase.value = 'uploading';

    try {
        // Preserves recording order regardless of which chunk's upload happened to finish
        // first — Promise.all resolves in input-array order, not completion order.
        const partialUrls = await Promise.all(chunkUploads);
        unguardUnload();

        if (partialUrls.length === 0) {
            phase.value = 'error';
            errorMessage.value = 'No audio was captured. Please try again.';
            return;
        }

        const response = await fetch(tusRoutes.create({ project: props.projectId }).url, {
            method: 'POST',
            headers: {
                ...csrfHeader(),
                'Tus-Resumable': '1.0.0',
                'Upload-Concat': `final;${partialUrls.join(' ')}`,
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        // Fetch-native counterparts of sessionExpiry.ts's axios-based helpers, which expect an
        // AxiosResponse/AxiosError shape this raw fetch call doesn't produce.
        if (response.status === 401 || response.status === 419) {
            goToLogin();
            return;
        }
        const contentType = response.headers.get('content-type');
        if (typeof contentType === 'string' && !contentType.includes('application/json')) {
            goToLogin();
            return;
        }

        if (!response.ok) {
            const body = await response.json().catch(() => null);
            throw new Error(body?.message ?? 'Failed to finish uploading the recording.');
        }

        const body = await response.json();

        // Same as every other transcript source (Google Doc, file, provider-imported
        // recording) — never leave the user on the raw transcript, go straight to the
        // Meeting Notes document it's about to generate (see RecordingIntakeService::store()
        // and summarize()). Falls back to the transcript itself only when no single-output
        // template is configured, matching IntakeImportService::import()'s own fallback.
        const targetId = body.recording.notes_id ?? body.recording.id;
        router.visit(projectDocumentsRoutes.show({ project: props.projectId, document: targetId }).url);
    } catch (error) {
        unguardUnload();
        phase.value = 'error';
        errorMessage.value = error instanceof Error ? error.message : 'Failed to upload the recording. Please try again.';
    }
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
            closed tab only risks the last few seconds, not the whole meeting.
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
