const DEFAULT_COLUMN_ORDER = ['todo', 'in_progress', 'review', 'done'];

/**
 * The Dashboard renders one shared board across every visible project, but each project
 * now has its own column set. Reconciles them into a single column list: the default
 * keys first (in their canonical order) if any project has them, then any custom keys in
 * order of first appearance across projects. A column's label/color come from the first
 * project (in that same scan order) that defines its key — divergent custom labels for the
 * same key across projects is a rare edge case this doesn't attempt to resolve further.
 */
export function mergeProjectColumns(projects: Project[]): KanbanColumnDef[] {
    const merged = new Map<string, KanbanColumnDef>();

    for (const key of DEFAULT_COLUMN_ORDER) {
        for (const project of projects) {
            const column = project.kanban_columns?.find((c) => c.key === key);
            if (column) {
                merged.set(key, column);
                break;
            }
        }
    }

    for (const project of projects) {
        for (const column of project.kanban_columns ?? []) {
            if (!merged.has(column.key)) {
                merged.set(column.key, column);
            }
        }
    }

    return Array.from(merged.values());
}
