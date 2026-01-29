import { useKanbanState } from './useKanbanState';
import { useKanbanQueries } from './useKanbanQueries';
import { useKanbanActions } from './useKanbanActions';
import { useKanbanDnD } from './useKanbanDnD';
import { useKanbanPermissions } from './useKanbanPermissions';
import { useKanbanRules } from './useKanbanRules';

/** * EXPORT these so the other files can see them
 */
export type DocumentUpdatePayload = Partial<ProjectDocument> & {
    task_status?: TaskStatus;
};

export interface KanbanProps {
    projects: Project[];
    currentProject: Project | null;
    kanbanData: Record<string, ProjectDocument[]>;
}

export function useKanbanBoard(props: KanbanProps) {

    const state = useKanbanState(props);
    const queries = useKanbanQueries(props, state.localKanbanData);
    const actions = useKanbanActions(props, state.applyLocalUpdate);
    const dnd = useKanbanDnD(props, actions.updateAttribute);
    const permissions = useKanbanPermissions();
    const rules = useKanbanRules(props, permissions);

    return {
        ...state,
        ...queries,
        ...actions,
        ...dnd,
        ...permissions,
        ...rules
    };
}
