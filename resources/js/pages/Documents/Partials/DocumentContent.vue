<script setup lang="ts">
import DocumentPreviewCard from '@/components/documents/DocumentPreviewCard.vue';
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import TaskRowContent from '@/components/documents/TaskRowContent.vue';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';
import { INTAKE_KEY } from '@/composables/useWorkflow';
import { mergeAssigneeOptions, mergeMentionableUsers } from '@/lib/assignees';
import { show as showDocument } from '@/routes/projects/documents';
import { Link, usePage, type InertiaForm } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import { CheckCircle2, CornerDownRight, CornerUpLeft } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
    (
        e: 'update-child-task',
        id: string | number,
        field: string,
        value: any,
    ): void;
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

// A single active id (not one ref per row) since every row here is a `v-for` iteration inside
// this one component instance, not a separate component instance the way TraceabilityRow.vue's
// rows are — a plain ref(false) per row isn't possible without keying by id anyway.
const openPreviewId = ref<string | number | null>(null);
const handlePreviewOpenChange = (id: string | number, open: boolean) => {
    if (open) {
        openPreviewId.value = id;
    } else if (openPreviewId.value === id) {
        openPreviewId.value = null;
    }
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
                    <Popover
                        v-for="(child, index) in childTaskList"
                        :key="child.id"
                        :open="openPreviewId === child.id"
                        @update:open="
                            (open) => handlePreviewOpenChange(child.id, open)
                        "
                    >
                        <PopoverAnchor as-child>
                            <div
                                class="group relative flex min-h-9 items-center gap-2.5 rounded-md pr-2 transition-colors"
                                :class="
                                    index % 2 === 1
                                        ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25'
                                        : ''
                                "
                            >
                                <TaskRowContent
                                    :doc="child"
                                    :columns="project.kanban_columns ?? []"
                                    :assignee-options="assigneeOptions"
                                    :uses-external-due-dates="
                                        usesExternalDueDates
                                    "
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
                                    @navigate="
                                        navigateToDetails(
                                            child.project_id,
                                            child.id,
                                        )
                                    "
                                    @hover-preview="
                                        (hovering) =>
                                            handlePreviewOpenChange(
                                                child.id,
                                                hovering,
                                            )
                                    "
                                />
                            </div>
                        </PopoverAnchor>
                        <PopoverContent
                            class="w-(--reka-popper-anchor-width) p-4"
                            align="end"
                        >
                            <DocumentPreviewCard
                                :name="child.name"
                                :content="child.content"
                            />
                        </PopoverContent>
                    </Popover>
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
