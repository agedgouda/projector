import { INTAKE_KEY } from '@/composables/useWorkflow';

/**
 * The distinct document types actually in use in a project, in the same "visible on the
 * Documentation tab" sense useDocumentTree.ts computes for its own folder grouping — Tasks and
 * Events are excluded (shown on their own tabs instead), and an intake document that already
 * has children is excluded too (hidden once it's been processed, same as its own folder would
 * be). Order is first-appearance in `documents`, which is however the caller's own data
 * already orders them — nothing prescribed here beyond "actually used, still visible".
 */
export function visibleDocumentTypeKeys(
    documents: ProjectDocument[],
    catalog: DocumentSchemaItem[],
): string[] {
    const isTaskType = (key: string) => catalog.find((t) => t.key === key)?.is_task ?? false;

    const parentIds = new Set<string>();
    documents.forEach((d) => {
        if (d.parent_id != null) parentIds.add(String(d.parent_id));
    });

    const seen = new Set<string>();
    const order: string[] = [];
    documents.forEach((d) => {
        if (d.type === 'event') return;
        if (isTaskType(d.type)) return;
        if (d.type === INTAKE_KEY && parentIds.has(String(d.id))) return;
        if (!seen.has(d.type)) {
            seen.add(d.type);
            order.push(d.type);
        }
    });

    return order;
}

/**
 * A type's catalog label when it has one (e.g. "intake" -> "Transcription" — already Title
 * Case, DocumentTypeDefinition normalizes it on save), or a best-effort fallback for a type
 * that was never added to the catalog at all (e.g. a one-off type like "event_list_import").
 * Only the fallback needs title-casing here — there's no model to do it for a type with no
 * catalog row at all — matches useDocumentTree.ts's own getGroupLabel, so a type reads the
 * same way here as it does on the Documentation tab itself.
 */
export function documentTypeLabel(key: string, catalog: DocumentSchemaItem[]): string {
    const catalogLabel = catalog.find((t) => t.key === key)?.label;
    if (catalogLabel) return catalogLabel;

    return key
        .replace(/_/g, ' ')
        .replace(/\w\S*/g, (word) => word.charAt(0).toUpperCase() + word.slice(1));
}
