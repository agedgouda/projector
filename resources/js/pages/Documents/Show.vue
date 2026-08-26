<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { computed, ref, watch, toRef } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Plus } from 'lucide-vue-next';

// Layouts & Components
import AppLayout from '@/layouts/AppLayout.vue';
import AiProgressBar from '@/components/AiProgressBar.vue';
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ReprocessPromptModal from '@/components/ReprocessPromptModal.vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import DocumentSidebar from './Partials/DocumentSidebar.vue';
import DocumentContent from './Partials/DocumentContent.vue';
import DocumentHeader from './Partials/DocumentHeader.vue';
import DocumentLayoutWrapper from './Partials/DocumentLayoutWrapper.vue';
import CommentSection from '@/components/comments/CommentSection.vue';
import { mergeMentionableUsers } from '@/lib/assignees';
import { kanbanDotClasses } from '@/lib/constants';

// Composables
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useDocumentForm } from '@/composables/documents/useDocumentForm';
import { useDocumentNavigation } from '@/composables/documents/useDocumentNavigation';
import { useEchoWatchdog } from '@/composables/useEchoWatchdog';
import { useWorkflow, reprocessDescription } from '@/composables/useWorkflow';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';

/* ---------------------------
   2. Props
---------------------------- */
const props = defineProps<{
    project: Project;
    item: ExtendedDocument;
    documentTypeCatalog?: DocumentSchemaItem[];
    boardOptions?: BoardOption[];
}>();

/* ---------------------------
   3. Logic Setup (Composables)
---------------------------- */
const {
    form,
    isEditing,
    isDeleting,
    isDeleteModalOpen,
    isReprocessPromptOpen,
    isReprocessing,
    isProcessingLive,
    processingMessage,
    aiProgress,
    toggleEdit,
    handleFormSubmit,
    confirmDeletion,
    confirmReprocess,
    syncSidebarFields,
} = useDocumentForm(props.project, props.item);

// props.item is replaced by Inertia after sidebar PATCH — sync form to avoid stale overwrites.
watch(toRef(props, 'item'), (newItem) => syncSidebarFields(newItem), { deep: false });

// Set by DocumentContent (bubbled up from InlineDocumentForm) while a pasted/dropped/attached
// file's upload is still in flight — saving before that finishes would persist content missing
// the file, since the upload's insertion into the editor happens asynchronously after the
// request completes.
const isUploading = ref(false);
const handleFormSubmitGuarded = () => {
    if (isUploading.value) {
        return;
    }
    handleFormSubmit();
};

// Surfaces a dropped live-updates connection and reconnects the socket — same pattern as
// Projects/Show.vue. Without this, a lost connection here fails silently: nothing tells the
// user their document isn't going to hear about processing completion via broadcast at all.
useEchoWatchdog(() => props.project.id);

const { breadcrumbs, handleBack } = useDocumentNavigation(props.project, props.item);

const { updateField, moveToBoard, updateTags } = useDocumentActions(
    { project: props.project, documentSchema: [] },
    ref('')
);

const handleMove = (targetProjectId: string) =>
    moveToBoard(props.item.id as string, targetProjectId, (message) => toast.error(message));

// Tags already on this task never show up again as an "add" option — only the family's
// remaining, not-yet-applied tags do.
const availableTagsToAdd = computed(() => {
    const appliedIds = new Set((props.item.categories ?? []).map((c) => c.id));
    return (props.project.categories ?? []).filter((c) => !appliedIds.has(c.id));
});

// An Event marks a single occurrence on the calendar, so only one tag makes sense — unlike
// every other document type, which can carry any number. Picking one here swaps out
// whatever tag it already had rather than adding a second.
const isSingleTagType = computed(() => props.item.type === 'event');

const addTag = (category: CategoryDef) =>
    updateTags(
        props.item.id as string,
        isSingleTagType.value ? [category] : [...(props.item.categories ?? []), category],
        (message) => toast.error(message),
    );

const removeTag = (category: CategoryDef) =>
    updateTags(
        props.item.id as string,
        (props.item.categories ?? []).filter((c) => c.id !== category.id),
        (message) => toast.error(message),
    );

/* ---------------------------
   4. Local UI State
---------------------------- */
const dueAtProxy = computed<string>({
    get: () => props.item.due_at?.substring(0, 10) ?? '',
    set: (val) => updateField(props.item.id as string, 'due_at', val)
});

const startAtProxy = computed<string>({
    get: () => props.item.start_at?.substring(0, 10) ?? '',
    set: (val) => updateField(props.item.id as string, 'start_at', val)
});

const usesExternalDueDates = computed(() => (page.props as any).orgMembership?.uses_external_due_dates ?? false);

// Lets an @-mention in the Discussion section resolve to a pending invitee (not just a
// registered user with a password) — same wiring as the content editor (DocumentContent.vue).
const mentionableUsers = computed(() =>
    mergeMentionableUsers(props.project.client?.organization?.users, props.project.client?.organization?.invitations),
);

// Derived from the live `props.item` (not a one-time snapshot) so it stays accurate across
// saves within the same page visit — same visibility rule as the tree/detail-sheet Reprocess
// button (see TraceabilityRow.vue's isReprocessable): an intake document, one locked to a
// protocol that still has a next step, or any document that's already been run through a
// template once before (see Document::lastAiTemplate()). Deliberately not staleness-gated —
// unlike the old needsReprocess rule, this shows regardless of whether content has changed
// since the last run, matching the tree exactly.
const { reprocessableTypes } = useWorkflow();
const isLocked = computed(() => !!props.item.locked_project_type_id);
const isReprocessable = computed(() =>
    reprocessableTypes.value.has(props.item.type)
    || (isLocked.value && !!props.item.locked_next_workflow_step_exists)
    || !!props.item.last_ai_template_id
);

// Matches processButtonLabel in TraceabilityRow.vue: "Reprocess" once this document has
// already produced output before (so running it again would overwrite something), "Process"
// the first time (nothing to overwrite yet).
const processButtonLabel = computed(() => props.item.children_exists ? 'Reprocess' : 'Process');

// Names the confirmation prompt after this document's actual type (e.g. "Reprocess Action
// Items?") instead of the generic "Reprocess Document?" fallback.
const { getDocLabel } = useDocumentPresenter(props.documentTypeCatalog);
const reprocessPromptTitle = computed(() => `Reprocess ${getDocLabel(props.item.type)}?`);

// Mirrors handleReprocess in DocumentManager.vue/Projects/Show.vue: skip the confirmation
// modal entirely when nothing would be overwritten yet.
const handleRequestProcess = () => {
    if (props.item.children_exists) {
        isReprocessPromptOpen.value = true;
    } else {
        void confirmReprocess();
    }
};

/* ---------------------------
   5. Watchers (Flash Messages)
---------------------------- */
const page = usePage<{ flash?: { success?: string; error?: string } }>();
watch(() => page.props.flash, (flash) => {
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
}, { deep: true, immediate: true });

</script>

<template>
    <Head :title="item.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <AiProgressBar
            :is-processing="isProcessingLive"
            :progress="aiProgress"
        />

        <AiProcessingHeader
            :is-processing="isProcessingLive"
            :progress="aiProgress"
            :message="processingMessage ?? ''"
        />

        <DocumentLayoutWrapper>
            <template #header>
                <DocumentHeader
                    :project="project"
                    :item="item"
                    :is-editing="isEditing"
                    :is-saving="form.processing || isUploading"
                    :save-label="isUploading ? 'Uploading…' : undefined"
                    save-button-class="w-24"
                    @back="handleBack"
                    @toggle-edit="toggleEdit"
                    @delete="isDeleteModalOpen = true"
                    @save="handleFormSubmitGuarded"
                />
            </template>

            <template #content>
                <DocumentContent
                    :item="item"
                    :project="project"
                    :document-type-catalog="documentTypeCatalog"
                    :is-editing="isEditing"
                    :metadata="form.metadata"
                    :form="form"
                    @submit="handleFormSubmitGuarded"
                    @cancel="toggleEdit"
                    @update:is-uploading="isUploading = $event"
                    @update-child-task="(id, field, val) => updateField(String(id), field, val)"
                />

                <div v-if="(project.categories?.length ?? 0) > 0" class="mt-12 pt-10 border-t border-slate-100 dark:border-slate-800">
                    <h3 class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-700 dark:text-slate-400 mb-6 flex items-center gap-2">
                        <div class="w-4 h-px bg-slate-400 dark:bg-slate-600"></div> Tags
                    </h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="category in item.categories ?? []"
                            :key="category.id"
                            type="button"
                            :title="`Remove '${category.name}' tag`"
                            :disabled="project.inactive"
                            class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-bold text-gray-700 hover:border-gray-300 disabled:pointer-events-none disabled:opacity-50 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-gray-200"
                            @click="removeTag(category)"
                        >
                            <span :class="[kanbanDotClasses[category.color], 'h-2 w-2 shrink-0 rounded-full']"></span>
                            {{ category.name }}
                        </button>

                        <Popover v-if="availableTagsToAdd.length && !project.inactive">
                            <PopoverTrigger as-child>
                                <button
                                    type="button"
                                    title="Add a tag"
                                    class="flex h-6 w-6 items-center justify-center rounded-full border border-dashed border-gray-300 text-gray-400 hover:border-projector-primary-300 hover:text-projector-primary-600"
                                >
                                    <Plus class="h-3.5 w-3.5" />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent class="w-48 p-1" align="start">
                                <button
                                    v-for="category in availableTagsToAdd"
                                    :key="category.id"
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs font-bold text-gray-700 hover:bg-slate-100 dark:text-gray-200 dark:hover:bg-white/10"
                                    @click="addTag(category)"
                                >
                                    <span :class="[kanbanDotClasses[category.color], 'h-2 w-2 shrink-0 rounded-full']"></span>
                                    {{ category.name }}
                                </button>
                            </PopoverContent>
                        </Popover>
                    </div>
                </div>

                <div class="mt-12 pt-10 border-t border-slate-100">
                    <h3 class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-700 dark:text-slate-400 mb-6 flex items-center gap-2">
                        <div class="w-4 h-px bg-slate-400 dark:bg-slate-600"></div> Discussion
                    </h3>
                    <CommentSection
                        :comments="item.comments ?? []"
                        commentable-type="document"
                        :commentable-id="item.id"
                        :mentionable-users="mentionableUsers"
                        :read-only="project.inactive"
                        :project-id="project.id"
                    />
                </div>
            </template>

            <template #sidebar>
                <DocumentSidebar
                    :item="item"
                    :project="project"
                    :document-type-catalog="documentTypeCatalog"
                    :uses-external-due-dates="usesExternalDueDates"
                    :is-reprocessable="isReprocessable"
                    :process-button-label="processButtonLabel"
                    :is-processing-live="isProcessingLive"
                    :processing-message="processingMessage"
                    :board-options="boardOptions"
                    v-model:dueAtProxy="dueAtProxy"
                    v-model:startAtProxy="startAtProxy"
                    @change="(field, val) => updateField(item.id as string, field, val)"
                    @request-process="handleRequestProcess"
                    @move="handleMove"
                />
            </template>
        </DocumentLayoutWrapper>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            title="Delete Document"
            :description="`Are you sure you want to delete '${item.name}'? This action cannot be undone.`"
            :loading="isDeleting"
            @confirm="confirmDeletion"
            @close="isDeleteModalOpen = false"
        />

        <ReprocessPromptModal
            :open="isReprocessPromptOpen"
            :title="reprocessPromptTitle"
            :description="reprocessDescription(item)"
            :loading="isReprocessing"
            @confirm="confirmReprocess"
            @close="isReprocessPromptOpen = false"
        />
    </AppLayout>
</template>
