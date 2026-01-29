import type { KanbanProps } from './useKanbanBoard';
import type { useKanbanPermissions } from './useKanbanPermissions';

export function useKanbanRules(
    props: KanbanProps,
    permissions: ReturnType<typeof useKanbanPermissions>
) {
    const { isAdmin } = permissions;


    /**
     * Rules for where the "New Task" button appears
     */
    const canCreateTask = (status: TaskStatus): boolean => {
        // Applying the same 'unknown' bridge here
        const creativeStatuses = ['backlog', 'todo'] as unknown as TaskStatus[];

        return creativeStatuses.includes(status) && isAdmin.value;
    };

    return {
        canCreateTask,
    };
}
