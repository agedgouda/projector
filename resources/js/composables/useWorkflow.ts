import { computed } from 'vue';

export function useWorkflow(project: any) {
    // 1. Extract the workflow array from the project type
    const workflow = computed(() => {
        // Handle potential naming variations (project.type vs project.project_type)
        const type = project.project_type || project.type;
        return type?.workflow || [];
    });

    // 2. Create a Set of from_keys for O(1) lookup
    // We use a computed Set so the rows don't re-calculate this for every item
    const reprocessableTypes = computed(() => {
        const workflowArr = workflow.value;
        // Explicitly map to strings and cast the Set
        return new Set<string>(
            workflowArr.map((step: any) => String(step.from_key))
        );
    });

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
