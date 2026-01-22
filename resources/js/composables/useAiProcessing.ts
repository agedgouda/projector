import { ref, computed, watch, onBeforeUnmount, type Ref } from 'vue';
import { useEcho } from '@laravel/echo-vue';
import { globalAiState } from '@/state';

export function useAiProcessing(
    projectId: number,
    allDocs: Ref<ExtendedDocument[]>,
    targetBeingCreated: Ref<string | number | null>,
    onDocumentUpdated?: (doc: ExtendedDocument) => void,
    onSuccess?: (message: string) => void,
    onError?: (message: string) => void
) {
    const aiStatusMessage = ref<string>('');
    const aiProgress = ref<number>(0);
    let creepInterval: ReturnType<typeof setInterval> | null = null;

    // --- 1. CLEANUP LOGIC ---
    const stopCreep = () => {
        if (creepInterval) {
            clearInterval(creepInterval);
            creepInterval = null;
        }
    };

    onBeforeUnmount(stopCreep);

    // --- 2. DERIVED STATE ---
    // AI is processing if there's an active target being created OR any document has null processed_at
    const isAiProcessing = computed(() =>
        !!targetBeingCreated.value || allDocs.value.some(d => d.processed_at === null)
    );

    // Sync Global AI State
    watch(isAiProcessing, (newVal) => {
        globalAiState.value.isProcessing = newVal;
        if (!newVal) {
            aiProgress.value = 0;
            aiStatusMessage.value = '';
            stopCreep();
        }
    }, { immediate: true });

    // --- 3. PROGRESS ANIMATION (The "Creep") ---
    watch(aiProgress, (newVal) => {
        stopCreep();
        // Only creep if we are in active progress but haven't reached the end
        if (newVal > 0 && newVal < 90) {
            creepInterval = setInterval(() => {
                if (aiProgress.value < newVal + 15 && aiProgress.value < 95) {
                    aiProgress.value += 0.5;
                }
            }, 1000);
        }
    });

    // --- 4. REAL-TIME LISTENER ---
    // Listens for both specific document updates and general processing progress
    useEcho(`project.${projectId}`, ['.document.vectorized', '.DocumentProcessingUpdate'], (payload: any) => {

        // Handle Status & Progress
        if (payload.statusMessage) {
            const message = String(payload.statusMessage);
            const msg = message.toLowerCase();
            const newProgress = Number(payload.progress || 0);

            const isError = msg.includes('error') || msg.includes('failed') || msg.includes('exhausted');
            const isSuccess = (msg.includes('success') || newProgress === 100) && !isError;

            if (isSuccess) {
                aiProgress.value = 100;
                onSuccess?.(message);
            } else if (isError) {
                aiProgress.value = 0;
                stopCreep();
                onError?.(message);
            }

            // Only advance progress, never go backwards unless it's a reset
            if (!isSuccess && newProgress > aiProgress.value) {
                aiProgress.value = newProgress;
            }

            aiStatusMessage.value = message;
        }

        // Handle Document Data Funnel
        if (payload.document && onDocumentUpdated) {
            // We pass the document to the funnel provided by useProjectState
            onDocumentUpdated(payload.document);
        }
    }, [projectId], 'private');

    return {
        aiStatusMessage,
        aiProgress,
        isAiProcessing
    };
}
