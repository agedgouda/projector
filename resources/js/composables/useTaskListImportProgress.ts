import { router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { ref } from 'vue';

interface TaskListImportProgressPayload {
    import_document_id: string;
    processed: number;
    total: number;
    status: 'running' | 'done' | 'error';
    redirect_url: string | null;
    message: string | null;
}

/**
 * Mirrors useAiProcessing.ts's shape ({isProcessing, progress, message}) so the same
 * AiProcessingHeader/AiProgressBar top-of-page banner this project page already shows for AI
 * processing can display a list import's live "X of Y" progress too — see
 * TaskListImportController::store(), which now only creates the task_list_import/
 * event_list_import Document and hands the row-by-row work to a queued ImportTaskList job,
 * broadcasting TaskListImportProgress as it goes.
 */
export function useTaskListImportProgress(projectId: string) {
    const isImporting = ref(false);
    const importProgress = ref(0);
    const importMessage = ref('');

    // Called the instant the confirm modal's Import button is clicked — before the request
    // to start the import has even been sent, let alone before any real progress broadcast
    // could exist — so the banner appears immediately instead of only once the first
    // TaskListImportProgress update arrives.
    const startImporting = () => {
        isImporting.value = true;
        importProgress.value = 0;
        importMessage.value = 'Starting Import';
    };

    useEcho(
        `project.${projectId}`,
        ['.TaskListImportProgress'],
        (payload: TaskListImportProgressPayload) => {
            if (payload.status === 'running') {
                isImporting.value = true;
                importProgress.value =
                    payload.total > 0
                        ? Math.round((payload.processed / payload.total) * 100)
                        : 0;
                importMessage.value = `Importing ${payload.processed} of ${payload.total}...`;

                return;
            }

            isImporting.value = false;

            if (payload.status === 'done' && payload.redirect_url) {
                router.visit(payload.redirect_url);
            }
        },
        [projectId],
        'private',
    );

    return { isImporting, importProgress, importMessage, startImporting };
}
