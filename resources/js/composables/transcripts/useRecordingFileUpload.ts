import { ref } from 'vue';
import axios from 'axios';
import * as tus from 'tus-js-client';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import tusRoutes from '@/routes/projects/browser-recordings/tus';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { type CustomPrompt } from '@/lib/constants';

// Matches RecordingIntakeService::MAX_UPLOAD_BYTES (TusUploadController's Tus-Max-Size) —
// checked here first so a too-big file is refused before minutes of uploading, not after.
export const MAX_RECORDING_UPLOAD_BYTES = 200 * 1024 * 1024;

// Small enough that no single PATCH body comes near the web server's request size limit.
const CHUNK_SIZE_BYTES = 5 * 1024 * 1024;

// Same reason as BrowserAudioCapture.vue's copy: tus-js-client doesn't read the XSRF-TOKEN
// cookie the way axios does.
const csrfHeader = (): Record<string, string> => {
    const cookie = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='));
    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.split('=')[1]) } : {};
};

// The tus creation request's own refusal (e.g. the transcription allowance is used up).
const tusErrorMessage = (error: unknown): string | null => {
    const body = (error as tus.DetailedError)?.originalResponse?.getBody();
    if (!body) return null;
    try {
        const message = JSON.parse(body)?.message;
        return typeof message === 'string' ? message : null;
    } catch {
        return null;
    }
};

const beforeUnloadHandler = (event: BeforeUnloadEvent) => {
    event.preventDefault();
    event.returnValue = '';
};

/**
 * Uploads an existing audio/video file (e.g. a Google Meet recording downloaded from Drive) as
 * one resumable tus upload through the same endpoint browser capture uses, then turns it into
 * a recording via TusUploadController::finishUpload() — transcribed exactly like a captured
 * one, and the user lands on its Meeting Notes document the same way.
 */
export function useRecordingFileUpload(projectId: string) {
    // Percent uploaded while an upload is running, null otherwise.
    const uploadProgress = ref<number | null>(null);

    const uploadFile = (file: File): Promise<string> => new Promise((resolve, reject) => {
        const upload = new tus.Upload(file, {
            endpoint: tusRoutes.create({ project: projectId }).url,
            headers: csrfHeader(),
            chunkSize: CHUNK_SIZE_BYTES,
            metadata: {
                filename: file.name,
                filetype: file.type,
                // Checked against the organization's transcription allowance before any bytes
                // are sent.
                starts_recording: 'true',
            },
            retryDelays: [0, 1000, 3000, 5000, 10000],
            onProgress: (sent, total) => {
                uploadProgress.value = total > 0 ? Math.floor((sent / total) * 100) : 0;
            },
            onSuccess: () => {
                const id = upload.url?.split('/').pop();
                if (id) resolve(id);
                else reject(new Error('Upload finished without a URL.'));
            },
            onError: (error) => reject(error),
        });
        upload.start();
    });

    const uploadRecordingFile = async (file: File, customPrompt: CustomPrompt | null) => {
        if (file.size > MAX_RECORDING_UPLOAD_BYTES) {
            toast.error('File too large', { description: 'Recordings can be up to 200 MB.' });
            return;
        }

        uploadProgress.value = 0;
        window.addEventListener('beforeunload', beforeUnloadHandler);

        try {
            const uploadId = await uploadFile(file);
            const response = await axios.post<{ recording: { id: string; notes_id: string | null } }>(
                tusRoutes.finishUpload({ project: projectId }).url,
                {
                    upload_id: uploadId,
                    name: file.name.replace(/\.[^.]+$/, '') || file.name,
                    custom_prompt: customPrompt?.text ?? null,
                    custom_prompt_mode: customPrompt?.mode ?? null,
                },
            );

            window.removeEventListener('beforeunload', beforeUnloadHandler);

            const targetId = response.data.recording.notes_id ?? response.data.recording.id;
            router.visit(projectDocumentsRoutes.show({ project: projectId, document: targetId }).url);
        } catch (error) {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
            uploadProgress.value = null;

            const description = axios.isAxiosError(error) && error.response?.data?.message
                ? String(error.response.data.message)
                : tusErrorMessage(error) ?? 'The recording could not be uploaded. Please try again.';
            toast.error('Upload failed', { description });
        }
    };

    return { uploadProgress, uploadRecordingFile };
}
