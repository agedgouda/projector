import type { KanbanProps, DocumentUpdatePayload } from './useKanbanBoard';

export function useKanbanDnD(
    props: KanbanProps,
    updateAttribute: (id: string | number, data: DocumentUpdatePayload, msg?: string) => void
) {
    const onDragChange = (evt: any, column: KanbanColumnDef) => {
        if (!evt.added) return;

        const doc = evt.added.element as ProjectDocument;

        updateAttribute(
            doc.id,
            { task_status: column.key },
            `Moved "${doc.name}" to ${column.label}`
        );
    };

    return { onDragChange };
}
