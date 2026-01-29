import { STATUS_LABELS } from '@/lib/constants';
import type { KanbanProps, DocumentUpdatePayload } from './useKanbanBoard';

export function useKanbanDnD(
    props: KanbanProps,
    updateAttribute: (id: string | number, data: DocumentUpdatePayload, msg?: string) => void
) {
    const onDragChange = (evt: any, newStatus: TaskStatus) => {
        if (!props.currentProject || !evt.added) return;

        const doc = evt.added.element as ProjectDocument;

        updateAttribute(
            doc.id,
            { task_status: newStatus },
            `Moved "${doc.name}" to ${STATUS_LABELS[newStatus]}`
        );
    };

    return { onDragChange };
}
