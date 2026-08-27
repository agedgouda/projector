<script setup lang="ts">
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import TaskRowContent from '@/components/documents/TaskRowContent.vue';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';
import { INTAKE_KEY } from '@/composables/useWorkflow';
import { mergeAssigneeOptions, mergeMentionableUsers } from '@/lib/assignees';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { formatDateOnly } from '@/lib/utils';
import { show as showDocument } from '@/routes/projects/documents';
import { Link, usePage, type InertiaForm } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import {
    Calendar as CalendarIcon,
    CornerDownRight,
    CornerUpLeft,
} from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    item: ExtendedDocument;
    isEditing: boolean;
    project: Project;
    documentTypeCatalog?: DocumentSchemaItem[];
    form: InertiaForm<DocumentFields>;
    categories?: CategoryDef[];
    availableTagsToAdd?: CategoryDef[];
    tagsReadOnly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'submit'): void;
    (e: 'cancel'): void;
    (e: 'update:isUploading', value: boolean): void;
    (
        e: 'update-child-task',
        id: string | number,
        field: string,
        value: any,
    ): void;
    (e: 'add-tag', category: CategoryDef): void;
    (e: 'remove-tag', category: CategoryDef): void;
}>();

const { navigateToDetails } = useDocumentActions({ project: props.project });

const handleFormSubmit = () => emit('submit');
const handleCancel = () => emit('cancel');

const { getDocLabel, isTask } = useDocumentPresenter(props.documentTypeCatalog);

const sanitize = (html: string | null) => DOMPurify.sanitize(html ?? '');

// Named generically ("document" rather than "child"/"parent") since it's used for both
// directions of the traceability chain. Sets `from` to *this* page (mirroring getAncestorUrl
// in useDocumentNavigation.ts and navigateToDetails in useDocumentActions.ts) — never
// forwards the `from` this page itself arrived with — so the back arrow on the document you
// land on always returns to the literal previous page, whether that's this document or,
// several hops earlier, a project tab.
const documentUrl = (documentId: string | number) => {
    const baseUrl = showDocument({
        project: props.item.project_id,
        document: String(documentId),
    }).url;
    const from = new URL(window.location.href);
    from.searchParams.delete('from');
    return `${baseUrl}?from=${encodeURIComponent(from.toString())}`;
};

// Exactly one generated document: a direct "View Generated X" link, mirroring "View Source
// X" above. More than one is handled separately below (as a task list, when applicable) —
// with more than one generated document there's no single obvious link target.
const singleChild = computed(() => {
    const children = props.item.children ?? [];
    return children.length === 1 ? children[0] : null;
});

// Transcriptions can be very long, so the link to whatever got generated from them (usually
// meeting notes) surfaces right under the title instead of requiring a scroll past the full
// transcript — every other document type keeps it at the bottom, near the content it follows
// from.
const isTranscription = computed(() => props.item.type === INTAKE_KEY);

// Multiple generated documents only get a summary view when they're all tasks — mirrors (and,
// via TaskRowContent.vue, shares the literal row markup of) the task rows on the project's
// Documentation tab (TraceabilityRow.vue), just as a flat list rather than an editable tree.
const childTaskList = computed(() => {
    const children = props.item.children ?? [];
    if (children.length <= 1) return [];

    return children.every((child) => isTask(child.type)) ? children : [];
});

// Same idea as childTaskList above, for a source document (e.g. Meeting Notes) whose
// generated children are all Events instead — Events aren't tasks, so they'd otherwise show
// up nowhere at all once there's more than one of them (singleChild only covers exactly one).
const childEventList = computed(() => {
    const children = props.item.children ?? [];
    if (children.length <= 1) return [];

    return children.every((child) => child.type === 'event') ? children : [];
});

// task_list_import/event_list_import documents store their content as a JSON dump of every
// imported row (see ImportTaskList::finish()) — shown as a formatted table below instead of
// the raw JSON text the generic content section would otherwise render.
const isImportRecord = computed(
    () =>
        props.item.type === 'task_list_import' ||
        props.item.type === 'event_list_import',
);

interface ImportedRow {
    [key: string]: string | null | undefined;
}

const importedRows = computed<ImportedRow[]>(() => {
    if (!isImportRecord.value || !props.item.content) return [];
    try {
        const parsed = JSON.parse(props.item.content);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
});

const importColumns = computed<{ key: string; label: string }[]>(() =>
    props.item.type === 'event_list_import'
        ? [
              { key: 'name', label: 'Name' },
              { key: 'tag', label: 'Tag' },
              { key: 'start_date', label: 'Start Date' },
              { key: 'due_date', label: 'End Date' },
              { key: 'description', label: 'Description' },
          ]
        : [
              { key: 'name', label: 'Name' },
              { key: 'priority', label: 'Priority' },
              { key: 'task_status', label: 'Status' },
              { key: 'due_at', label: 'Due Date' },
              { key: 'assignee', label: 'Assignee' },
              { key: 'tag', label: 'Tag' },
          ],
);

const formatImportCell = (value: string | null | undefined): string => {
    if (!value) return '—';
    // Only date-shaped values (due_at/start_date/due_date) get reformatted — name/tag/
    // assignee/description etc. are already plain display text.
    if (/^\d{4}-\d{2}-\d{2}/.test(value)) return formatEventDate(value);
    return value;
};

const importCreatedCount = computed<number>(
    () => props.item.metadata?.created_count ?? importedRows.value.length,
);
const importOriginalFilename = computed<string | null>(
    () => props.item.metadata?.original_filename ?? null,
);
const importSkippedRows = computed<{ row: number; reason: string }[]>(
    () => props.item.metadata?.skipped ?? [],
);
const importUntaggedRows = computed<{ row: number; tag: string }[]>(
    () => props.item.metadata?.untagged ?? [],
);

// Matches TraceabilityRow.vue's own non-task date-range formatting, so an event reads the
// same way here as it does in the Documents tree.
const formatEventDate = formatDateOnly;
const eventDateRange = (child: ExtendedDocument): string | null => {
    const start = child.start_at ? formatEventDate(child.start_at) : null;
    const end = child.due_at ? formatEventDate(child.due_at) : null;
    if (!start && !end) return null;
    if (start && end && start !== end) return `${start} – ${end}`;
    return end ?? start;
};

// Lets an @-mention resolve to a pending invitee (not just a registered user with a
// password) — mirrors the assignee picker in DocumentSidebar.vue/KanbanCard.vue, which
// already merges the two via the same `inv:` id convention.
const mentionableUsers = computed(() =>
    mergeMentionableUsers(
        props.project.client?.organization?.users,
        props.project.client?.organization?.invitations,
    ),
);

// Same merged users+invitations list the document assignee picker itself uses — feeds the
// "Generated Tasks" rows' assignee field (see TaskRowContent.vue).
const assigneeOptions = computed(() =>
    mergeAssigneeOptions(
        props.project.client?.organization?.users,
        props.project.client?.organization?.invitations,
    ),
);

const page = usePage();
const usesExternalDueDates = computed(
    () => (page.props as any).orgMembership?.uses_external_due_dates ?? false,
);

</script>

<template>
    <div class="space-y-12">
        <div
            v-if="isEditing"
            class="rounded-2xl border border-slate-200 bg-slate-50 p-6"
        >
            <InlineDocumentForm
                mode="edit"
                :form="form"
                :mentionable-users="mentionableUsers"
                :project-id="project.id"
                :categories="categories"
                :available-tags-to-add="availableTagsToAdd"
                :tags-read-only="tagsReadOnly"
                @submit="handleFormSubmit"
                @cancel="handleCancel"
                @update:is-uploading="emit('update:isUploading', $event)"
                @add-tag="(category) => emit('add-tag', category)"
                @remove-tag="(category) => emit('remove-tag', category)"
            />
        </div>

        <div v-else class="space-y-12">
            <section>
                <h1
                    class="mb-2 text-2xl font-bold text-slate-900 dark:text-slate-100"
                >
                    {{ item.name }}
                </h1>
                <Link
                    v-if="item.parent"
                    :href="documentUrl(item.parent.id)"
                    class="mb-4 inline-flex items-center gap-1.5 text-[11px] font-black tracking-[0.2em] text-slate-400 uppercase transition-colors hover:text-projector-primary-600 dark:hover:text-projector-primary-400"
                >
                    <CornerUpLeft class="h-3 w-3" />
                    View Source {{ getDocLabel(item.parent.type) }}
                </Link>
                <Link
                    v-if="isTranscription && singleChild"
                    :href="documentUrl(singleChild.id)"
                    class="mb-4 inline-flex items-center gap-1.5 text-[11px] font-black tracking-[0.2em] text-slate-400 uppercase transition-colors hover:text-projector-primary-600 dark:hover:text-projector-primary-400"
                >
                    <CornerDownRight class="h-3 w-3" />
                    View Generated {{ getDocLabel(singleChild.type) }}
                </Link>
                <div class="mb-6 flex items-center gap-3">
                    <h3
                        class="text-[11px] font-black tracking-[0.2em] text-slate-900 uppercase dark:text-slate-200"
                    >
                        {{ getDocLabel(item.type) }}
                    </h3>
                </div>
                <div
                    v-if="!isImportRecord"
                    class="max-w-none text-[15px] leading-relaxed text-slate-900 dark:text-slate-400"
                    v-html="
                        sanitize(item.content) || 'No description provided.'
                    "
                ></div>
            </section>

            <section v-if="isImportRecord">
                <div
                    class="mb-4 flex flex-wrap items-center gap-2 text-[13px] text-slate-600 dark:text-slate-400"
                >
                    <span>
                        Imported {{ importCreatedCount }} of
                        {{ importedRows.length }} row{{
                            importedRows.length === 1 ? '' : 's'
                        }}
                    </span>
                    <span
                        v-if="importOriginalFilename"
                        class="text-slate-400 dark:text-slate-500"
                    >
                        from {{ importOriginalFilename }}
                    </span>
                </div>

                <div
                    v-if="importSkippedRows.length"
                    class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30"
                >
                    <p
                        class="mb-2 text-[11px] font-black tracking-widest text-amber-700 uppercase dark:text-amber-400"
                    >
                        {{ importSkippedRows.length }} row{{
                            importSkippedRows.length === 1 ? '' : 's'
                        }}
                        skipped
                    </p>
                    <ul
                        class="space-y-1 text-[13px] text-amber-800 dark:text-amber-300"
                    >
                        <li
                            v-for="skipped in importSkippedRows"
                            :key="skipped.row"
                        >
                            Row {{ skipped.row }}: {{ skipped.reason }}
                        </li>
                    </ul>
                </div>

                <div
                    v-if="importUntaggedRows.length"
                    class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30"
                >
                    <p
                        class="mb-2 text-[11px] font-black tracking-widest text-amber-700 uppercase dark:text-amber-400"
                    >
                        {{ importUntaggedRows.length }} row{{
                            importUntaggedRows.length === 1 ? '' : 's'
                        }}
                        missing a tag
                    </p>
                    <ul
                        class="space-y-1 text-[13px] text-amber-800 dark:text-amber-300"
                    >
                        <li
                            v-for="untagged in importUntaggedRows"
                            :key="untagged.row"
                        >
                            Row {{ untagged.row }}: "{{ untagged.tag }}" — the
                            project ran out of available tag colors.
                        </li>
                    </ul>
                </div>

                <div
                    v-if="importedRows.length"
                    class="overflow-x-auto rounded-xl border border-slate-200 dark:border-white/10"
                >
                    <table class="w-full text-[13px]">
                        <thead class="bg-slate-50 dark:bg-white/5">
                            <tr>
                                <th
                                    v-for="column in importColumns"
                                    :key="column.key"
                                    class="px-3 py-2 text-left font-bold text-slate-500 dark:text-slate-400"
                                >
                                    {{ column.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in importedRows"
                                :key="index"
                                class="border-t border-slate-100 dark:border-white/10"
                            >
                                <td
                                    v-for="column in importColumns"
                                    :key="column.key"
                                    class="px-3 py-2 text-slate-700 dark:text-slate-300"
                                >
                                    {{ formatImportCell(row[column.key]) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="singleChild && !isTranscription">
                <Link
                    :href="documentUrl(singleChild.id)"
                    class="inline-flex items-center gap-1.5 text-[11px] font-black tracking-[0.2em] text-slate-400 uppercase transition-colors hover:text-projector-primary-600 dark:hover:text-projector-primary-400"
                >
                    <CornerDownRight class="h-3 w-3" />
                    View Generated {{ getDocLabel(singleChild.type) }}
                </Link>
            </section>

            <section v-if="childTaskList.length">
                <h3
                    class="mb-6 flex items-center gap-2 text-[11px] font-black tracking-[0.2em] text-slate-700 uppercase dark:text-slate-400"
                >
                    <div class="h-px w-4 bg-slate-400 dark:bg-slate-600"></div>
                    Generated Tasks
                </h3>
                <div>
                    <div
                        v-for="(child, index) in childTaskList"
                        :key="child.id"
                        class="group relative flex min-h-9 cursor-pointer items-center gap-2.5 rounded-md px-2 transition-colors"
                        :class="[
                            FLAT_ROW_HOVER,
                            index % 2 === 1
                                ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25'
                                : '',
                        ]"
                        @click="navigateToDetails(child.project_id, child.id)"
                    >
                        <TaskRowContent
                            :doc="child"
                            :columns="project.kanban_columns ?? []"
                            :assignee-options="assigneeOptions"
                            :uses-external-due-dates="usesExternalDueDates"
                            :read-only="project.inactive"
                            @update="
                                (field, val) =>
                                    emit(
                                        'update-child-task',
                                        child.id,
                                        field,
                                        val,
                                    )
                            "
                        />
                    </div>
                </div>
            </section>

            <section v-if="childEventList.length">
                <h3
                    class="mb-6 flex items-center gap-2 text-[11px] font-black tracking-[0.2em] text-slate-700 uppercase dark:text-slate-400"
                >
                    <div class="h-px w-4 bg-slate-400 dark:bg-slate-600"></div>
                    Generated Events
                </h3>
                <div>
                    <Link
                        v-for="(child, index) in childEventList"
                        :key="child.id"
                        :href="documentUrl(child.id)"
                        class="group relative flex min-h-9 items-center gap-2.5 rounded-md px-2 transition-colors hover:text-projector-primary-600 dark:hover:text-projector-primary-400"
                        :class="[
                            FLAT_ROW_HOVER,
                            index % 2 === 1
                                ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25'
                                : '',
                        ]"
                    >
                        <span
                            class="min-w-0 flex-1 truncate text-[13px] font-medium text-slate-900 dark:text-slate-100"
                        >
                            {{ child.name }}
                        </span>
                        <span
                            v-if="eventDateRange(child)"
                            class="flex shrink-0 items-center gap-1 text-[11px] font-bold text-slate-400 dark:text-slate-500"
                        >
                            <CalendarIcon class="h-3 w-3" />
                            {{ eventDateRange(child) }}
                        </span>
                    </Link>
                </div>
            </section>
        </div>
    </div>
</template>
<style scoped>
/* The :deep() selector is required because the AI-generated HTML
  is injected (likely via v-html) and isn't part of the
  template during initial compilation.
*/
:deep(ol) {
    list-style-type: decimal !important;
    padding-left: 1.5rem !important;
    margin-top: 1rem;
    margin-bottom: 1rem;
}

:deep(ol li) {
    margin-bottom: 0.5rem;
}

/* Optional: Style <ul> tags while you're at it */
:deep(ul) {
    list-style-type: disc !important;
    padding-left: 1.5rem !important;
}

:deep(p) {
    margin-top: 1rem;
    margin-bottom: 1rem;
}

:deep(h1),
:deep(h2),
:deep(h3),
:deep(h4) {
    font-weight: 800;
    color: rgb(15 23 42);
    margin-top: 1.75rem;
    margin-bottom: 0.75rem;
}
:global(html.dark) :deep(h1),
:global(html.dark) :deep(h2),
:global(html.dark) :deep(h3),
:global(html.dark) :deep(h4) {
    color: rgb(241 245 249);
}

:deep(h1) {
    font-size: 1.5rem;
}
:deep(h2) {
    font-size: 1.25rem;
}
:deep(h3) {
    font-size: 1.1rem;
}
:deep(h4) {
    font-size: 1rem;
}

:deep(strong) {
    font-weight: 700;
}

:deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    margin-bottom: 1rem;
}

:deep(th),
:deep(td) {
    border: 1px solid rgb(226 232 240);
    padding: 0.5rem 0.75rem;
    text-align: left;
    vertical-align: top;
}

:deep(th) {
    font-weight: 700;
    background-color: rgb(248 250 252);
}

:global(html.dark) :deep(th),
:global(html.dark) :deep(td) {
    border-color: rgb(51 65 85);
}

:global(html.dark) :deep(th) {
    background-color: rgb(30 41 59);
}
</style>
