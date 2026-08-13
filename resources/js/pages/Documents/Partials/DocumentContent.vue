<script setup lang="ts">
import { computed } from 'vue';
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';
import { Link, type InertiaForm } from '@inertiajs/vue3';
import { show as showDocument } from '@/routes/projects/documents';
import { invitationName, mergeMentionableUsers } from '@/lib/assignees';
import { kanbanDotClasses, priorityDotClasses, PRIORITY_LABELS } from '@/lib/constants';
import { getAvatarAppearance } from '@/lib/kanban-theme';
import DOMPurify from 'dompurify';
import { CheckCircle2, CornerUpLeft, CornerDownRight, CheckSquare } from 'lucide-vue-next';
import { INTAKE_KEY } from '@/composables/useWorkflow';

// The partial metadata interface for the "View" mode section
interface DocumentMetadata {
    criteria?: string[];
}

const props = defineProps<{
    item: ExtendedDocument;
    isEditing: boolean;
    metadata: DocumentMetadata | null;
    project: Project;
    documentTypeCatalog?: DocumentSchemaItem[];
    form: InertiaForm<DocumentFields>;
}>();

const emit = defineEmits<{
    (e: 'submit'): void;
    (e: 'cancel'): void;
    (e: 'update:isUploading', value: boolean): void;
}>();

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
    const baseUrl = showDocument({ project: props.item.project_id, document: String(documentId) }).url;
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

// Multiple generated documents only get a summary view when they're all tasks — mirrors the
// task rows on the project's Documentation tab (TraceabilityRow.vue), simplified for a flat,
// read-only list rather than an editable tree.
const childTaskList = computed(() => {
    const children = props.item.children ?? [];
    if (children.length <= 1) return [];

    return children.every((child) => isTask(child.type)) ? children : [];
});

// Lets an @-mention resolve to a pending invitee (not just a registered user with a
// password) — mirrors the assignee picker in DocumentSidebar.vue/KanbanCard.vue, which
// already merges the two via the same `inv:` id convention.
const mentionableUsers = computed(() =>
    mergeMentionableUsers(props.project.client?.organization?.users, props.project.client?.organization?.invitations),
);

const statusFor = (statusKey: string | null) =>
    props.project.kanban_columns?.find((column) => column.key === statusKey);

const childAssigneeLabel = (child: ProjectDocument): string => {
    if (child.assignee) return child.assignee.name;
    if (child.pending_assignee) return invitationName(child.pending_assignee);
    return 'Unassigned';
};

const initials = (label: string): string => {
    const parts = label.trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
};
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
                @submit="handleFormSubmit"
                @cancel="handleCancel"
                @update:is-uploading="emit('update:isUploading', $event)"
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
                    class="mb-4 inline-flex items-center gap-1.5 text-[11px] font-black uppercase tracking-[0.2em] text-slate-400 transition-colors hover:text-projector-primary-600 dark:hover:text-projector-primary-400"
                >
                    <CornerUpLeft class="h-3 w-3" />
                    View Source {{ getDocLabel(item.parent.type) }}
                </Link>
                <Link
                    v-if="isTranscription && singleChild"
                    :href="documentUrl(singleChild.id)"
                    class="mb-4 inline-flex items-center gap-1.5 text-[11px] font-black uppercase tracking-[0.2em] text-slate-400 transition-colors hover:text-projector-primary-600 dark:hover:text-projector-primary-400"
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
                    class="max-w-none text-[15px] leading-relaxed text-slate-900 dark:text-slate-400"
                    v-html="
                        sanitize(item.content) || 'No description provided.'
                    "
                ></div>
            </section>

            <section v-if="metadata?.criteria?.length">
                <h3
                    class="mb-6 flex items-center gap-2 text-[11px] font-black tracking-[0.2em] text-slate-700 uppercase dark:text-slate-400"
                >
                    <div class="h-px w-4 bg-slate-400 dark:bg-slate-600"></div>
                    Success Criteria
                </h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div
                        v-for="(criterion, index) in metadata.criteria"
                        :key="index"
                        class="group flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 transition-colors hover:border-emerald-200 dark:border-white/10 dark:bg-white/5 dark:hover:border-emerald-900"
                    >
                        <CheckCircle2
                            class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500"
                        />
                        <span
                            class="text-[15px] leading-relaxed text-slate-900 dark:text-slate-300"
                            >{{ criterion }}</span
                        >
                    </div>
                </div>
            </section>

            <section v-if="singleChild && !isTranscription">
                <Link
                    :href="documentUrl(singleChild.id)"
                    class="inline-flex items-center gap-1.5 text-[11px] font-black uppercase tracking-[0.2em] text-slate-400 transition-colors hover:text-projector-primary-600 dark:hover:text-projector-primary-400"
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
                <div class="grid gap-0.5">
                    <Link
                        v-for="child in childTaskList"
                        :key="child.id"
                        :href="documentUrl(child.id)"
                        class="flex items-center gap-2.5 rounded-md px-2 py-2 transition-colors hover:bg-slate-100 dark:hover:bg-white/5"
                    >
                        <CheckSquare class="h-3.5 w-3.5 shrink-0 text-slate-400" />

                        <span class="min-w-0 flex-1 truncate text-[13px] font-medium text-slate-900 dark:text-slate-100">
                            {{ child.name }}
                        </span>

                        <span v-if="child.priority" class="flex shrink-0 items-center gap-1.5">
                            <span :class="['h-1.5 w-1.5 shrink-0 rounded-full', priorityDotClasses[child.priority] ?? priorityDotClasses.low]"></span>
                            <span class="text-[9px] font-bold uppercase tracking-wider text-slate-400">{{ PRIORITY_LABELS[child.priority] ?? child.priority }}</span>
                        </span>

                        <span class="flex shrink-0 items-center gap-1.5">
                            <span :class="['h-1.5 w-1.5 shrink-0 rounded-full', kanbanDotClasses[statusFor(child.task_status)?.color ?? 'slate']]"></span>
                            <span class="text-[9px] font-bold uppercase tracking-wider text-slate-400">{{ statusFor(child.task_status)?.label ?? child.task_status ?? 'todo' }}</span>
                        </span>

                        <span
                            :class="['flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-[9px] font-black uppercase', getAvatarAppearance(child.assignee?.id ?? 0)]"
                            :title="childAssigneeLabel(child)"
                        >
                            {{ initials(childAssigneeLabel(child)) }}
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
