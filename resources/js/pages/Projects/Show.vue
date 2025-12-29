<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectHeader from './Partials/ProjectHeader.vue';
import DocumentManager from './Partials/DocumentManager.vue';
import { computed, ref } from 'vue';
import { router, useForm, Head } from '@inertiajs/vue3';
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
    DialogTitle,
    DialogFooter
} from '@/components/ui/dialog';

const props = defineProps<{
    project: any;
    projectTypes: any[];
    breadcrumbs: any[];
}>();

const isEditModalOpen = ref(false);
const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);
const isUploadModalOpen = ref(false);
const isGenerating = ref(false);
const documentToDelete = ref<any>(null);

const uploadForm = useForm({
    name: '',
    type: '',
    content: '',
});

const origin = computed(() => new URLSearchParams(window.location.search).get('from'));

const requirementStatus = computed(() => {
    const schema = (props.project.type.document_schema as any[]) || [];
    const allDocs = (props.project.documents as any[]) || [];
    return schema.map((req) => {
        const matchingDocs = allDocs.filter((doc) => doc.type === req.key);
        return { ...req, documents: matchingDocs, isUploaded: matchingDocs.length > 0 };
    });
});

const canGenerate = computed(() => requirementStatus.value.filter(r => r.required).every(r => r.isUploaded));


const openUploadModal = (requirement?: any) => {
    uploadForm.type = requirement?.key || '';
    uploadForm.name = '';
    uploadForm.content = '';
    isUploadModalOpen.value = true;
};

const submitDocument = () => {
    uploadForm.post(projectDocumentsRoutes.store.url(props.project.id), {
        onSuccess: () => {
            isUploadModalOpen.value = false;
            uploadForm.reset();
        },
    });
};

const confirmDocumentDeletion = (doc: any) => {
    documentToDelete.value = doc;
    isDeleteModalOpen.value = true;
};

const handleDeleteConfirmed = () => {
    isDeleting.value = true;

    if (documentToDelete.value) {
        router.delete(projectDocumentsRoutes.destroy.url(props.project.id, documentToDelete.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                isDeleteModalOpen.value = false;
                documentToDelete.value = null;
            },
            onFinish: () => (isDeleting.value = false),
        });
    } else {
        // Project Deletion Route
        const destination = origin.value === 'index'
            ? projectRoutes.index.url()
            : `/clients/${props.project.client_id}`;

        router.delete(projectRoutes.destroy.url(props.project.id), {
            data: { redirect_to: destination },
            onSuccess: () => {
                isDeleteModalOpen.value = false;
            },
            onFinish: () => (isDeleting.value = false),
        });
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
            <ProjectHeader :project="project" :project-types="projectTypes" :origin="origin" />


            <DocumentManager
                :requirement-status="requirementStatus"
                :can-generate="canGenerate"
                :is-generating="isGenerating"
                @open-upload="openUploadModal"
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

        <Dialog v-model:open="isUploadModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Upload {{ requirementStatus.find(r => r.key === uploadForm.type)?.label || 'Document' }}</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitDocument" class="space-y-4 py-4">
                    <div>
                        <label class="text-sm font-medium">Document Type</label>
                        <select v-model="uploadForm.type" class="w-full mt-1 border-slate-200 rounded-md">
                            <option value="" disabled>Select a type...</option>
                            <option v-for="req in requirementStatus" :key="req.key" :value="req.key">{{ req.label }}</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-medium">Document Name</label>
                        <input v-model="uploadForm.name" type="text" class="flex h-9 w-full rounded-md border border-input px-3 py-1 text-sm" />
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-medium">Content</label>
                        <textarea v-model="uploadForm.content" rows="12" class="flex min-h-[200px] w-full rounded-md border border-input px-3 py-2 text-sm"></textarea>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="isUploadModalOpen = false">Cancel</Button>
                        <Button type="submit" :disabled="uploadForm.processing">Save Document</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            :title="documentToDelete ? 'Delete Document' : 'Delete ' + project.name"
            :description="documentToDelete ? `Are you sure you want to delete '${documentToDelete.name}'?` : 'Are you sure you want to delete this project?'"
            :loading="isDeleting"
            @confirm="handleDeleteConfirmed"
            @close="isDeleteModalOpen = false; documentToDelete = null"
        />
    </AppLayout>
</template>
