export function useDocumentPresenter(catalog?: DocumentSchemaItem[]) {
    const getDocLabel = (typeKey: string | null | undefined): string => {
        if (!typeKey) return '';

        const found = catalog?.find((s) => s.key === typeKey);

        // Return the catalog label if it exists, otherwise the formatted key
        return found?.label || typeKey.replace(/_/g, ' ');
    };

    const isTask = (typeKey: string | null | undefined): boolean => {
        if (!typeKey) return false;
        const found = catalog?.find((s) => s.key === typeKey);
        return !!found?.is_task;
    };

    return { getDocLabel, isTask };
}
