import { onBeforeUnmount, watch, type Ref } from 'vue';

/**
 * Nudges `progress` forward on its own between real updates so it never sits dead still during
 * a gap between broadcasts — capped short of the next real checkpoint (+15, ceiling 95) so an
 * actual update always visibly overtakes it. Previously duplicated identically in
 * useAiProcessing.ts (project-wide document processing) and useDocumentForm.ts (single-document
 * processing); extracted here so both share one timer implementation.
 *
 * Callers that need to stop the creep immediately (e.g. before a synchronous reset of
 * `progress` to 0, ahead of the watcher's own flush) should call the returned `stop`.
 */
export function useProgressCreep(progress: Ref<number>) {
    let creepInterval: ReturnType<typeof setInterval> | null = null;

    const stop = () => {
        if (creepInterval) {
            clearInterval(creepInterval);
            creepInterval = null;
        }
    };

    watch(progress, (newVal) => {
        stop();
        if (newVal > 0 && newVal < 90) {
            creepInterval = setInterval(() => {
                if (progress.value < newVal + 15 && progress.value < 95) {
                    progress.value += 0.5;
                }
            }, 1000);
        }
    });

    onBeforeUnmount(stop);

    return { stop };
}
