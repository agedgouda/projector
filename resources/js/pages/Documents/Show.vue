<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { computed, ref, watch, toRef } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

// Layouts & Components
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import DocumentSidebar from './Partials/DocumentSidebar.vue';
import DocumentContent from './Partials/DocumentContent.vue';
import DocumentHeader from './Partials/DocumentHeader.vue';
import DocumentLayoutWrapper from './Partials/DocumentLayoutWrapper.vue';
import CommentSection from '@/components/comments/CommentSection.vue';

// Composables
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useDocumentForm } from '@/composables/documents/useDocumentForm';
import { useDocumentNavigation } from '@/composables/documents/useDocumentNavigation';
import { useWorkflow } from '@/composables/useWorkflow';

/* ---------------------------
   2. Props
---------------------------- */
const props = defineProps<{
    project: Project;
    item: ExtendedDocument;
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
    toggleEdit,
    handleFormSubmit,
    confirmDeletion,
    confirmReprocess,
    syncSidebarFields,
} = useDocumentForm(props.project, props.item);

// props.item is replaced by Inertia after sidebar PATCH — sync form to avoid stale overwrites.
watch(toRef(props, 'item'), (newItem) => syncSidebarFields(newItem), { deep: false });

const { breadcrumbs, handleBack } = useDocumentNavigation(props.project, props.item);

const { updateField } = useDocumentActions(
    { project: props.project, documentSchema: [] },
    ref('')
);

/* ---------------------------
   4. Local UI State
---------------------------- */
const dueAtProxy = computed<string>({
    get: () => props.item.due_at?.substring(0, 10) ?? '',
    set: (val) => updateField(props.item.id as string, 'due_at', val)
});

// Derived from the live `props.item` (not a one-time snapshot) so it stays accurate across
// saves within the same page visit — a document has something to reprocess once its content
// has been edited more recently than the last successful AI run, and its type is actually
// reprocessable (an intake document, or one locked to a protocol that still has a next step).
const { reprocessableTypes } = useWorkflow();
const needsReprocess = computed(() => {
    const item = props.item;
    const isLocked = !!item.locked_project_type_id;
    const isReprocessableType = reprocessableTypes.value.has(item.type) || (isLocked && !!item.locked_next_workflow_step_exists);

    if (!isReprocessableType || !item.content_updated_at) return false;
    if (!item.processed_at) return true;

    return new Date(item.content_updated_at) > new Date(item.processed_at);
});

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
        <DocumentLayoutWrapper>
            <template #header>
                <DocumentHeader
                    :project="project"
                    :item="item"
                    :is-editing="isEditing"
                    :is-saving="form.processing"
                    @back="handleBack"
                    @toggle-edit="toggleEdit"
                    @delete="isDeleteModalOpen = true"
                    save-label="Update Document"
                    @save="handleFormSubmit"
                />
            </template>

            <template #content>
                <DocumentContent
                    :item="item"
                    :project="project"
                    :is-editing="isEditing"
                    :metadata="form.metadata"
                    :form="form"
                    @submit="() => handleFormSubmit()"
                    @cancel="toggleEdit"
                />

                <div class="mt-12 pt-10 border-t border-slate-100">
                    <h3 class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-700 dark:text-slate-400 mb-6 flex items-center gap-2">
                        <div class="w-4 h-px bg-slate-400 dark:bg-slate-600"></div> Discussion
                    </h3>
                    <CommentSection
                        :comments="item.comments ?? []"
                        commentable-type="document"
                        :commentable-id="item.id"
                        :mentionable-users="project.client?.organization?.users ?? []"
                        :read-only="project.inactive"
                    />
                </div>
            </template>

            <template #sidebar>
                <DocumentSidebar
                    :item="item"
                    :project="project"
                    :needs-reprocess="needsReprocess"
                    :is-processing-live="isProcessingLive"
                    :processing-message="processingMessage"
                    v-model:dueAtProxy="dueAtProxy"
                    @change="(field, val) => updateField(item.id as string, field, val)"
                    @request-process="isReprocessPromptOpen = true"
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

        <ConfirmDeleteModal
            :open="isReprocessPromptOpen"
            title="Reprocess Document?"
            description="Reprocessing will regenerate this document's output from its current content, overwriting anything previously generated. Continue?"
            confirm-label="Yes"
            cancel-label="No"
            confirm-variant="default"
            :loading="isReprocessing"
            @confirm="confirmReprocess"
            @close="isReprocessPromptOpen = false"
        />
    </AppLayout>
</template>
