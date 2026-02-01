<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

// Layouts & Components
import AppLayout from '@/layouts/AppLayout.vue';
import DocumentSidebar from './Partials/DocumentSidebar.vue';
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import DocumentHeader from './Partials/DocumentHeader.vue';
import DocumentLayoutWrapper from './Partials/DocumentLayoutWrapper.vue';
import { useDocumentNavigation } from '@/composables/documents/useDocumentNavigation';

// Routes & Logic
import projectDocumentsRoutes from '@/routes/projects/documents/';



/* ---------------------------
   2. Props
---------------------------- */
const props = defineProps<{
    project: Project;
    redirectUrl: string | null;
}>();

/* ---------------------------
   3. Form Setup (Draft Mode)
---------------------------- */
const form = useForm<DocumentForm & { project_id: string }>({
    name: '',
    content: '',
    type: '',
    assignee_id: null,
    due_at: null,
    priority: 'low',
    task_status: 'todo',
    project_id: props.project.id,
    metadata: {
        criteria: [] as string[]
    }
});

/* ---------------------------
   4. Reactive & Computed State
---------------------------- */
const { breadcrumbs, handleBack } = useDocumentNavigation(props.project, form);

// This computed property now perfectly matches the simplified Header prop
const draftItem = computed(() => ({
    name: form.name || 'New Document'
}));
/* ---------------------------
   5. Action Handlers
---------------------------- */
const handleFormSubmit = () => {
    const baseUrl = projectDocumentsRoutes.store({ project: props.project.id }).url;
    const finalUrl = props.redirectUrl
        ? `${baseUrl}?${new URLSearchParams({ redirect: props.redirectUrl }).toString()}`
        : baseUrl;

    form.post(finalUrl, {
        onSuccess: () => toast.success('Document created successfully'),
        onError: () => toast.error('Please correct the errors.')
    });
};

const handleCancel = () => {
    if (props.redirectUrl) {
        return router.visit(props.redirectUrl);
    }
    handleBack();
};

const updateFormValue = (field: string, val: any) => {
    (form as any)[field] = val;
};
</script>

<template>
    <Head title="New Document" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <DocumentLayoutWrapper>
            <template #header>
                <DocumentHeader
                    :project="project"
                    :item="draftItem"
                    :is-editing="true"
                    @back="handleCancel"
                    @toggle-edit="handleCancel"
                />
            </template>

            <template #content>
                <div class="bg-slate-50 rounded-2xl p-8 border border-slate-200">
                    <InlineDocumentForm
                        mode="create"
                        :form="form"
                        :document_schema="project.type.document_schema"
                        @submit="handleFormSubmit"
                        @cancel="handleCancel"
                    />
                </div>
            </template>

            <template #sidebar>
                <DocumentSidebar
                    :item="(form as any)"
                    :project="project"
                    :dueAtProxy="form.due_at ?? ''"
                    @update:dueAtProxy="(val) => form.due_at = val"
                    @change="updateFormValue"
                />
            </template>
        </DocumentLayoutWrapper>
    </AppLayout>
</template>
