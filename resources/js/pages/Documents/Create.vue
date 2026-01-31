<script setup lang="ts">
/* ---------------------------
   1. Imports & Types
---------------------------- */
import { computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ArrowLeft } from 'lucide-vue-next';

// Layouts & Components
import AppLayout from '@/layouts/AppLayout.vue';
import DocumentSidebar from './Partials/DocumentSidebar.vue';
import InlineDocumentForm from '@/components/documents/InlineDocumentForm.vue';

// Routes & Logic
import projectRoutes from '@/routes/projects/index';
import projectDocumentsRoutes from '@/routes/projects/documents/';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';

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
    priority: 'low',      // TS now knows this must match TaskPriority
    task_status: 'todo',  // TS now knows this must match TaskStatus
    project_id: props.project.id,
    metadata: {
        criteria: [] as string[] // Cast this to prevent 'never[]' error
    }
});

/* ---------------------------
   4. Reactive & Computed State
---------------------------- */
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Projects', href: projectRoutes.index.url() },
    { title: props.project.name, href: projectRoutes.show(props.project.id).url },
    { title: 'New Document', href: '' }
]);

/* ---------------------------
   5. Action Handlers
---------------------------- */
const handleFormSubmit = () => {
    // 1. Get the clean RESTful path from Wayfinder
    const baseUrl = projectDocumentsRoutes.store({ project: props.project.id }).url;

    // 2. Attach the "Context" (the redirect) to the URL Query String
    // This is the "best" way because navigation is a concern of the URL, not the Payload.
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
    router.visit(
        dashboard({
            project: props.project.id,
            tab: 'tasks'
        } as any).url
    );
};

// Local update only - no API calls during creation
const updateFormValue = (field: string, val: any) => {
    (form as any)[field] = val;
};
</script>

<template>
    <Head title="New Document" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-screen bg-white">
            <nav class="border-b bg-slate-50/50 px-8 py-4">
                <div class="max-w-7xl mx-auto flex items-center justify-between">
                    <div class="flex items-center gap-4 text-sm text-slate-500">
                        <Link
                            :href="projectRoutes.show(project.id).url"
                            class="hover:text-indigo-600 transition-colors flex items-center gap-2"
                        >
                            <ArrowLeft class="w-3 h-3" />
                            {{ project.name }}
                        </Link>
                        <span class="text-slate-300">/</span>
                        <span class="font-medium text-slate-900 truncate max-w-[300px]">New Document</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-black uppercase tracking-widest text-slate-400 mr-2">
                            Draft Mode
                        </span>
                    </div>
                </div>
            </nav>

            <main class="max-w-7xl mx-auto px-8 py-10">
                <div class="grid grid-cols-12 gap-12">
                    <div class="col-span-12 lg:col-span-8">
                        <div class="bg-slate-50 rounded-2xl p-8 border border-slate-200">
                            <InlineDocumentForm
                                mode="create"
                                :form="form"
                                :document_schema="project.type.document_schema"
                                @submit="handleFormSubmit"
                                @cancel="handleCancel"
                            />
                        </div>
                    </div>

                    <aside class="col-span-12 lg:col-span-4">
                        <DocumentSidebar
                            :item="(form as any)"
                            :project="project"
                            :dueAtProxy="form.due_at ?? ''"
                            @update:dueAtProxy="(val) => form.due_at = val"
                            @change="updateFormValue"
                        />
                    </aside>
                </div>
            </main>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Matching the exact focus and UI reset from Show.vue */
:deep([data-radix-collection-item]),
:deep(button[role="combobox"]),
.custom-date-input:focus {
    outline: none !important;
    box-shadow: none !important;
    border-color: transparent !important;
}

:deep(button[role="combobox"]:focus) {
    background-color: rgb(241 245 249);
}
</style>
