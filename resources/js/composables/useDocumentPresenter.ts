export function useDocumentPresenter(project?: Project) {
    const getDocLabel = (typeKey: string | null | undefined): string => {
        if (!typeKey) return '';

        const schema = project?.type?.document_schema || [];
        const found = schema.find((s) => s.key === typeKey);

        // Return the schema label if it exists, otherwise the formatted key
        return found?.label || typeKey.replace(/_/g, ' ');
    };

    const isTask = (typeKey: string | null | undefined): boolean => {
        if (!typeKey) return false;
        const schema = project?.type?.document_schema || [];
        const found = schema.find((s) => s.key === typeKey);
        return !!found?.is_task;
    };

    return { getDocLabel, isTask };
}
