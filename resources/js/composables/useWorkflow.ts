import { computed } from 'vue';

// Mirrors config('workflow.intake_key') on the backend — Notes are the only document type ever
// processed automatically (see DocumentObserver::created()). Every other type's next step is a
// user-chosen protocol or AI template (see TransformPicker.vue), so there's no single "the
// transition" to blindly re-run for anything else.
export const INTAKE_KEY = 'intake';

export function useWorkflow() {
    const reprocessableTypes = computed(() => new Set<string>([INTAKE_KEY]));

    /**
     * Determines if a specific document can be reprocessed.
     * Use this if you need logic inside the parent;
     * otherwise, rows can just check the Set.
     */
    const canReprocess = (doc: any) => {
        if (!doc) return false;

        const isCorrectType = reprocessableTypes.value.has(doc.type);
        const isNotProcessing = !doc.currentStatus;
        const hasFinishedInitial = doc.processed_at !== null;

        return isCorrectType && isNotProcessing && hasFinishedInitial;
    };

    return {
        reprocessableTypes,
        canReprocess
    };
}
