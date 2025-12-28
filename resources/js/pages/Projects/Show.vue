<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head,router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { ChevronLeft } from 'lucide-vue-next';
import projectRoutes from '@/routes/projects/index';
import ProjectIcon from '@/components/ProjectIcon.vue';
import ProjectForm from '@/components/ProjectForm.vue'
import { Dialog, DialogContent, DialogHeader, DialogTitle,DialogDescription } from '@/components/ui/dialog';
import ToastNotification from '@/components/ToastNotification.vue';
import { Trash2 } from 'lucide-vue-next';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';

const props = defineProps<{
    project: any;
    projectTypes: any[];
}>();


const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: projectRoutes.index.url() },
    { title: props.project.name, href: '#' },
];

const origin = computed(() => {
    return new URLSearchParams(window.location.search).get('from');
});

const handleBack = () => {
    if (origin.value === 'index') {
        router.visit('/projects');
    } else {
        // Default to the client page if 'client' or if the tag is missing
        router.visit(`/clients/${props.project.client_id}`);
    }
};

const backLabel = computed(() => {
    return origin.value === 'index' ? 'Back to Projects' : 'Back to Client';
});

const isEditModalOpen = ref(false);

const handleSuccess = () => {
    isEditModalOpen.value = false;
};

const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);

const handleDeleteConfirmed = () => {
    // Determine where to go based on your existing 'origin' logic
    const destination = origin.value === 'index'
        ? projectRoutes.index.url()
        : `/clients/${props.project.client_id}`;

    router.delete(projectRoutes.destroy.url(props.project.id), {
        // Pass the destination to the controller
        data: {
            redirect_to: destination
        },
        onBefore: () => {
            isDeleting.value = true;
        },
        onSuccess: () => {
            isDeleteModalOpen.value = false;
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
};
</script>


<template>
    <Head :title="project.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-7xl mx-auto w-full">
            <button
                @click="handleBack"
                class="inline-flex items-center text-sm text-gray-500 hover:text-indigo-600 transition-colors mb-6 focus:outline-none group"
            >
                <ChevronLeft class="w-4 h-4 mr-1 group-hover:-translate-x-1 transition-transform" />
                {{ backLabel }}
            </button>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden mb-8">
                <div class="p-6 flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="flex items-start md:items-center gap-5">
                        <div class="p-3.5 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl text-indigo-600 dark:text-indigo-400 shadow-sm">
                            <ProjectIcon :name="project.type?.icon" class="w-9 h-9" />
                        </div>

                        <div>
                            <div class="flex flex-wrap items-center gap-3 mb-1.5">
                                <h1 class="text-3xl font-black text-gray-900 dark:text-white tracking-tight">
                                    {{ project.name }}
                                </h1>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-widest bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-800">
                                    {{ project.status || 'Active' }}
                                </span>
                            </div>

                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                <p class="flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                                    <span class="text-gray-400 uppercase text-[10px] font-bold tracking-wider">Client</span>
                                    <span class="font-bold text-gray-700 dark:text-gray-200">{{ project.client.company_name }}</span>
                                </p>
                                <div class="hidden md:block w-1.5 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700"></div>
                                <p class="flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                                    <span class="text-gray-400 uppercase text-[10px] font-bold tracking-wider">Type</span>
                                    <span class="font-bold text-indigo-600 dark:text-indigo-400">{{ project.type?.name || 'General' }}</span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-4 md:pt-0 border-t md:border-t-0 border-gray-100 dark:border-gray-700">
                        <Button @click="isEditModalOpen = true" variant="outline" class="font-bold text-xs uppercase tracking-widest px-6 shadow-sm border-gray-200 dark:border-gray-700">
                            Edit Project
                        </Button>
                    </div>

                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-3">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                            <h2 class="font-black text-gray-400 uppercase text-[10px] tracking-[0.2em]">Project Description</h2>
                        </div>
                        <div class="p-6">
                            <p class="text-gray-600 dark:text-gray-300 leading-relaxed whitespace-pre-wrap text-base">
                                {{ project.description || 'No description provided for this project.' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-8 bg-white dark:bg-gray-800 rounded-xl border border-red-100 dark:border-red-900/30 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-red-50 dark:border-red-900/20 bg-red-50/50 dark:bg-red-900/10">
                    <h2 class="font-black text-red-600 dark:text-red-400 uppercase text-[10px] tracking-[0.2em]">Danger Zone</h2>
                </div>
                <div class="p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Delete this project</p>
                        <p class="text-sm text-gray-500">Once you delete a project, there is no going back. Please be certain.</p>
                    </div>
                    <Button
                        variant="destructive"
                        @click="isDeleteModalOpen = true"
                        class="font-bold text-xs uppercase tracking-widest px-6 shadow-sm"
                    >
                        <Trash2 class="w-4 h-4 mr-2" />
                        Delete Project
                    </Button>
                </div>
            </div>
        </div>

        <Dialog :open="isEditModalOpen" @update:open="isEditModalOpen = $event">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Edit Project Details</DialogTitle>
                    <DialogDescription>
                        Update the project information below.
                    </DialogDescription>
                </DialogHeader>

                <div class="py-4">
                    <ProjectForm
                        :project="project"
                        :project-types="projectTypes"
                        @success="handleSuccess"
                    />
                </div>
            </DialogContent>
        </Dialog>

=
        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            :title="'Delete ' + project.name"
            :description="'Are you sure you want to delete this project? This action cannot be undone.'"
            :loading="isDeleting"
            @confirm="handleDeleteConfirmed"
            @close="isDeleteModalOpen = false"
        />



        <ToastNotification />
    </AppLayout>
</template>
