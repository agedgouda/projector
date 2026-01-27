import { computed } from 'vue';

export function useWorkflow(project: Project) {
    // Create a Set of from_keys for O(1) lookup
    const reprocessableTypes = computed(() => {
        const workflow = project.type?.workflow || [];
        // Extract all 'from_key' values into a Set
        return new Set(workflow.map(step => step.from_key));
    });

    /**
     * Determines if a specific document can be reprocessed
     */
    const canReprocess = (doc: ExtendedDocument) => {
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
