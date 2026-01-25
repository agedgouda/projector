<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectHeader from './Partials/ProjectHeader.vue';
import DocumentManager from '@/components/documents/DocumentManager.vue';
import TaskMasterList from '@/components/tasks/TaskMasterList.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Trash2 } from 'lucide-vue-next';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import projectRoutes from '@/routes/projects/index';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { useProjectDashboard } from '@/composables/useProjectDashboard';

const props = defineProps<{
    project: Project;
    projectTypes: ProjectType[];
    users?: User[];
    origin?: string | null;
}>();

// --- COMPOSABLE LOGIC ---
const {
    requirementStatus,
    canGenerate,
    isDeleteModalOpen,
    isDeleting,
    documentToDelete,
    confirmDelete,
    deleteAction,
    activeTab,
    isEditModalOpen,
    handleBack,
    backLabel
} = useProjectDashboard(props);

// --- LOCAL STATE ---
const isGenerating = ref(false);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: projectRoutes.index.url() },
    { title: props.project.name, href: '#' },
];

// --- METHODS ---
const generateDeliverables = () => {
    router.post(projectRoutes.generate.url(props.project.id), {}, {
        onBefore: () => { isGenerating.value = true; },
        onFinish: () => { isGenerating.value = false; }
    });
};

const handleSuccess = () => {
    isEditModalOpen.value = false;
};

const confirmFinalDeletion = () => {
    deleteAction({
        projects: projectRoutes,
        documents: projectDocumentsRoutes
    });
};
</script>

<template>
    <Head :title="project.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-7xl mx-auto w-full">
            <ProjectHeader
                :project="project"
                :origin="origin ?? null"
                :active-tab="activeTab"
                :back-label="backLabel"
                @update:active-tab="(tab) => activeTab = tab"
                @edit="isEditModalOpen = true"
                @back="handleBack"
            />

            <div class="mt-6">
                <div v-show="activeTab === 'docs'">
                    <DocumentManager
                        :project="project"
                        :requirement-status="requirementStatus"
                        :can-generate="canGenerate"
                        :is-generating="isGenerating"
                        @confirm-delete="confirmDelete"
                        @generate="generateDeliverables"
                    />
                </div>

                <div v-show="activeTab === 'tasks'">
                    <TaskMasterList
                        :tasks="project.tasks || []"
                        :users="project.client?.users || []"
                    />
                </div>
            </div>

            <div class="mt-12 bg-white dark:bg-gray-800 rounded-xl border border-red-100 dark:border-red-900/30 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-red-50 dark:border-red-900/20 bg-red-50/50 dark:bg-red-900/10">
                    <h2 class="font-black text-red-600 dark:text-red-400 uppercase text-[10px] tracking-[0.2em]">Danger Zone</h2>
                </div>
                <div class="p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Delete this project</p>
                        <p class="text-sm text-gray-500">Once you delete a project, there is no going back.</p>
                    </div>
                    <Button variant="destructive" @click="confirmDelete()" class="font-bold text-xs uppercase tracking-widest px-6 shadow-sm">
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
            @confirm="confirmFinalDeletion"
            @close="isDeleteModalOpen = false; documentToDelete = null"
        />
    </AppLayout>
</template>
