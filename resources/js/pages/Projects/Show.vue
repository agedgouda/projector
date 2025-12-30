<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectHeader from './Partials/ProjectHeader.vue';
import DocumentManager from './Partials/DocumentManager.vue';
import ProposedStories from './Partials/ProposedStories.vue';
import { type BreadcrumbItem } from '@/types';
import { computed, ref } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Trash2 } from 'lucide-vue-next';
import projectRoutes from '@/routes/projects/index';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle
} from '@/components/ui/dialog';

const props = defineProps<{
    project: any;
    projectTypes: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: projectRoutes.index.url() },
    { title: props.project.name, href: '#' },
];

const isEditModalOpen = ref(false);
const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);
const isGenerating = ref(false);
const documentToDelete = ref<any>(null);

const origin = computed(() => new URLSearchParams(window.location.search).get('from'));

// Logic for calculating status and requirements based on project schema
const requirementStatus = computed(() => {
    const schema = (props.project.type.document_schema as any[]) || [];
    const allDocs = (props.project.documents as any[]) || [];
    return schema.map((req) => {
        const matchingDocs = allDocs.filter((doc) => doc.type === req.key);
        return {
            ...req,
            documents: matchingDocs,
            isUploaded: matchingDocs.length > 0
        };
    });
});

const canGenerate = computed(() =>
    requirementStatus.value.filter(r => r.required).every(r => r.isUploaded)
);

const confirmDocumentDeletion = (doc: any) => {
    documentToDelete.value = doc;
    isDeleteModalOpen.value = true;
};

const handleDeleteConfirmed = () => {
    isDeleting.value = true;

    try {
        if (documentToDelete.value) {
            // DOCUMENT DELETE
            const url = projectDocumentsRoutes.destroy.url({
                project: props.project.id,
                document: documentToDelete.value.id
            });

            router.delete(url, {
                onSuccess: () => {
                    isDeleteModalOpen.value = false;
                    documentToDelete.value = null;
                },
                onFinish: () => isDeleting.value = false,
                preserveScroll: true
            });
        } else {
            // PROJECT DELETE
            const url = projectRoutes.destroy.url({ project: props.project.id });
            router.delete(url, {
                onSuccess: () => { isDeleteModalOpen.value = false; },
                onFinish: () => isDeleting.value = false
            });
        }
    } catch (e) {
        console.error("Deletion Error:", e);
        isDeleting.value = false;
    }
};

const generateDeliverables = () => {
    router.post(projectRoutes.generate.url(props.project.id), {}, {
        onBefore: () => { isGenerating.value = true; },
        onFinish: () => { isGenerating.value = false; }
    });
};

const handleSuccess = () => { isEditModalOpen.value = false; };
</script>

<template>
    <Head :title="project.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-7xl mx-auto w-full">

            <ProposedStories :project-id="project.id" />
            <ProjectHeader
                :project="project"
                :project-types="projectTypes"
                :origin="origin"
            />

            <DocumentManager
                :project="project"
                :requirement-status="requirementStatus"
                :can-generate="canGenerate"
                :is-generating="isGenerating"
                :project-documents-routes="projectDocumentsRoutes"
                @confirm-delete="confirmDocumentDeletion"
                @generate="generateDeliverables"
            />


            <div class="mt-8 bg-white dark:bg-gray-800 rounded-xl border border-red-100 dark:border-red-900/30 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-red-50 dark:border-red-900/20 bg-red-50/50 dark:bg-red-900/10">
                    <h2 class="font-black text-red-600 dark:text-red-400 uppercase text-[10px] tracking-[0.2em]">Danger Zone</h2>
                </div>
                <div class="p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Delete this project</p>
                        <p class="text-sm text-gray-500">Once you delete a project, there is no going back.</p>
                    </div>
                    <Button variant="destructive" @click="isDeleteModalOpen = true" class="font-bold text-xs uppercase tracking-widest px-6 shadow-sm">
                        <Trash2 class="w-4 h-4 mr-2" /> Delete Project
                    </Button>
                </div>
            </div>
        </div>

        <Dialog :open="isEditModalOpen" @update:open="isEditModalOpen = $event">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Edit Project Details</DialogTitle>
                </DialogHeader>
                <div class="py-4">
                    <ProjectForm :project="project" :project-types="projectTypes" @success="handleSuccess" />
                </div>
            </DialogContent>
        </Dialog>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            :title="documentToDelete ? 'Delete Document' : 'Delete ' + project.name"
            :description="documentToDelete
                ? `Are you sure you want to delete '${documentToDelete.name}'?`
                : 'Are you sure you want to delete this project?'"
            :loading="isDeleting"
            @confirm="handleDeleteConfirmed"
            @close="isDeleteModalOpen = false; documentToDelete = null"
        />
    </AppLayout>
</template>
