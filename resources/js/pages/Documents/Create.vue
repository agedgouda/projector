<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { FilePlus2 } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

// Layouts & Components
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';
import { Button } from '@/components/ui/button';
import { useDocumentNavigation } from '@/composables/documents/useDocumentNavigation';
import { useDocumentPresenter } from '@/composables/useDocumentPresenter';
import { mergeMentionableUsers } from '@/lib/assignees';
import AppLayout from '@/layouts/AppLayout.vue';
import DocumentHeader from './Partials/DocumentHeader.vue';
import DocumentLayoutWrapper from './Partials/DocumentLayoutWrapper.vue';
import DocumentSidebar from './Partials/DocumentSidebar.vue';

// Routes & Logic
import projectDocumentsRoutes from '@/routes/projects/documents/';

/* ---------------------------
   2. Props
---------------------------- */
const props = defineProps<{
    project: Project;
    documentTypeCatalog?: DocumentSchemaItem[];
    redirectUrl: string | null;
    defaultType?: string | null;
}>();

/* ---------------------------
   3. Form Setup (Draft Mode)
---------------------------- */
const form = useForm<DocumentForm & { project_id: string }>({
    name: '',
    content: '',
    type: props.defaultType ?? '',
    assignee_id: null,
    due_at: null,
    external_due_at: null,
    priority: 'low',
    task_status: 'todo',
    project_id: props.project.id,
    metadata: {
        criteria: [] as string[],
    },
    custom_prompt: null,
});

/* ---------------------------
   4. Reactive & Computed State
---------------------------- */
const { breadcrumbs, handleBack } = useDocumentNavigation(props.project, form);

const page = usePage();
const usesExternalDueDates = computed(
    () => (page.props as any).orgMembership?.uses_external_due_dates ?? false,
);

// This computed property now perfectly matches the simplified Header prop
const draftItem = computed(() => ({
    name: form.name || 'New Document',
}));

// Every project starts with these document types; everything else in the catalog is
// produced by transformations, not picked by hand at creation time.
const MANUALLY_CREATABLE_TYPE_KEYS = ['intake', 'action_items', 'task'];
const creatableDocumentTypes = computed(() =>
    (props.documentTypeCatalog ?? []).filter((item) =>
        MANUALLY_CREATABLE_TYPE_KEYS.includes(item.key),
    ),
);

const { getDocLabel } = useDocumentPresenter(creatableDocumentTypes.value);
const saveLabel = computed(
    () => `Create ${getDocLabel(form.type) || 'Document'}`,
);

// Lets an @-mention resolve to a pending invitee (not just a registered user with a
// password) — see DocumentContent.vue's identical wiring for the document detail page.
const mentionableUsers = computed(() =>
    mergeMentionableUsers(props.project.client?.organization?.users, props.project.client?.organization?.invitations),
);

// Set by InlineDocumentForm while a pasted/dropped/attached file's upload is still in
// flight — saving before that finishes would persist content missing the file, since the
// upload's insertion into the editor happens asynchronously after the request completes.
const isUploading = ref(false);

/* ---------------------------
   5. Action Handlers
---------------------------- */
const handleFormSubmit = () => {
    if (isUploading.value) {
        return;
    }

    const baseUrl = projectDocumentsRoutes.store({
        project: props.project.id,
    }).url;
    const finalUrl = props.redirectUrl
        ? `${baseUrl}?${new URLSearchParams({ redirect: props.redirectUrl }).toString()}`
        : baseUrl;

    form.post(finalUrl, {
        onSuccess: () => toast.success('Document created successfully'),
        onError: () => toast.error('Please correct the errors.'),
    });
};

// "Save and New": save the current document, then land back on this create form (still set
// to the same type) instead of navigating away — store()'s existing `redirect` query param is
// reused to send the post-save visit straight back here. Inertia reuses this page's component
// instance across that round-trip rather than remounting it, so `form` still holds the
// just-submitted values once `onSuccess` fires; the type is captured up front since
// `form.reset()` would otherwise revert it to this page's original default type.
const buildCreateUrl = (type: string): string => {
    const baseUrl = projectDocumentsRoutes.create({
        project: props.project.id,
    }).url;
    return `${baseUrl}?${new URLSearchParams({ type }).toString()}`;
};

const saveAndRedirectToCreate = (type: string) => {
    const storeUrl = projectDocumentsRoutes.store({
        project: props.project.id,
    }).url;
    return `${storeUrl}?${new URLSearchParams({ redirect: buildCreateUrl(type) }).toString()}`;
};

const handleSaveAndNew = () => {
    if (isUploading.value) {
        return;
    }

    const currentType = form.type;
    form.post(saveAndRedirectToCreate(currentType), {
        onSuccess: () => {
            toast.success('Document created — ready for the next one.');
            form.reset();
            form.clearErrors();
            form.type = currentType;
        },
        onError: () => toast.error('Please correct the errors.'),
    });
};

onMounted(() => {
    const typeParam = new URLSearchParams(window.location.search).get('type');
    if (typeParam) {
        form.type = typeParam;
    }
});

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
                    :save-label="isUploading ? 'Uploading…' : saveLabel"
                    :is-saving="form.processing || isUploading"
                    @back="handleCancel"
                    @toggle-edit="handleCancel"
                    @save="handleFormSubmit"
                >
                    <template #extra-actions>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="form.processing || isUploading"
                            @click="handleSaveAndNew"
                            class="h-8 px-4 text-[10px] font-black tracking-widest uppercase"
                        >
                            <FilePlus2 class="mr-1.5 h-3 w-3" />
                            Save and New
                        </Button>
                    </template>
                </DocumentHeader>
            </template>

            <template #content>
                <div
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-8 dark:border-white/10 dark:bg-white/5"
                >
                    <InlineDocumentForm
                        mode="create"
                        :form="form"
                        :document_schema="creatableDocumentTypes"
                        :mentionable-users="mentionableUsers"
                        :project-id="project.id"
                        @submit="handleFormSubmit"
                        @cancel="handleCancel"
                        @update:is-uploading="isUploading = $event"
                    />
                </div>
            </template>

            <template #sidebar>
                <DocumentSidebar
                    :item="form as any"
                    :project="project"
                    :document-type-catalog="documentTypeCatalog"
                    :uses-external-due-dates="usesExternalDueDates"
                    :dueAtProxy="form.due_at ?? ''"
                    @update:dueAtProxy="(val) => (form.due_at = val)"
                    @change="updateFormValue"
                />
            </template>
        </DocumentLayoutWrapper>
    </AppLayout>
</template>
