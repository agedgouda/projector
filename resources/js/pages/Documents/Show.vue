<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

// Layouts & Components
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import DocumentSidebar from './Partials/DocumentSidebar.vue';
import DocumentContent from './Partials/DocumentContent.vue';
import DocumentHeader from './Partials/DocumentHeader.vue';
import DocumentLayoutWrapper from './Partials/DocumentLayoutWrapper.vue';

// Composables
import { useDocumentActions } from '@/composables/useDocumentActions';
import { useDocumentForm } from '@/composables/documents/useDocumentForm';
import { useDocumentNavigation } from '@/composables/documents/useDocumentNavigation';

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
    toggleEdit,
    handleFormSubmit,
    confirmDeletion
} = useDocumentForm(props.project, props.item);

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
                    @back="handleBack"
                    @toggle-edit="toggleEdit"
                    @delete="isDeleteModalOpen = true"
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
            </template>

            <template #sidebar>
                <DocumentSidebar
                    :item="item"
                    :project="project"
                    v-model:dueAtProxy="dueAtProxy"
                    @change="(field, val) => updateField(item.id as string, field, val)"
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
    </AppLayout>
</template>
