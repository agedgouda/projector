// Shared between TaskListImportConfirmModal.vue (a single confirmed list_type, chosen by
// which button opened it) and ImportTransformationModal.vue (one or more passes, each with
// its own list_type, proposed by SpreadsheetClassificationService or a saved transformation)
// — both edit the exact same {field: header} mapping shape StoreTaskListImportRequest and
// ApplyImportTransformationRequest validate, so the field list only needs to live in one place.

// A <Select> can't use an empty string as a real value (Radix reserves it for "no selection"
// display), so an unmapped field is represented by this sentinel locally and converted back to
// null only when building the request payload.
export const IGNORE = '__ignore__';

export type ImportFieldKey =
    | 'name'
    | 'priority'
    | 'task_status'
    | 'due_at'
    | 'assignee'
    | 'start_date'
    | 'description'
    | 'tag';

export interface ImportFieldDef {
    key: ImportFieldKey;
    label: string;
    required?: boolean;
}

export const TASK_FIELDS: ImportFieldDef[] = [
    { key: 'name', label: 'Task Name', required: true },
    { key: 'priority', label: 'Priority' },
    { key: 'task_status', label: 'Status' },
    { key: 'due_at', label: 'Due Date' },
    { key: 'assignee', label: 'Assignee' },
    { key: 'tag', label: 'Tag' },
];

// Events don't have priority/status/assignee — they have a start and end date instead of a
// single due date, and an optional description. Due date doubles as "End Date" here since it's
// the same underlying field the calendar and the "Notes to Events" transformation both use.
// Tag matches an existing project tag by name (like the AI transformation does) — it never
// creates a new one, and only the first tag column value per row is used since events can only
// carry a single tag.
export const EVENT_FIELDS: ImportFieldDef[] = [
    { key: 'name', label: 'Event Name', required: true },
    { key: 'description', label: 'Description' },
    { key: 'start_date', label: 'Start Date' },
    { key: 'due_at', label: 'End Date' },
    { key: 'tag', label: 'Tag' },
];

export const fieldsForListType = (
    listType: 'task' | 'event',
): ImportFieldDef[] => (listType === 'task' ? TASK_FIELDS : EVENT_FIELDS);
