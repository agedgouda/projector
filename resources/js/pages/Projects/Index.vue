<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { type BreadcrumbItem } from '@/types';

import projectRoutes from '@/routes/projects/index';

const { projects, clients, documents } = defineProps<{
    projects: any[];
    clients: any[];
    documents: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: projectRoutes.index.url() },
];

const form = useForm({
    name: '',
    client_id: '',
    document_id: '',
    description: '',
});

// Delete Modal State
const isDeleteModalOpen = ref(false);
const projectToDelete = ref<{ id: string; name: string } | null>(null);
const deleteForm = useForm({});

const submit = () => {
    form.post(projectRoutes.store.url(), {
        onSuccess: () => form.reset(),
    });
};

const confirmDelete = (project: any) => {
    projectToDelete.value = { id: project.id, name: project.name };
    isDeleteModalOpen.value = true;
};

const executeDelete = () => {
    if (!projectToDelete.value) return;
    deleteForm.delete(projectRoutes.destroy.url(projectToDelete.value.id), {
        onSuccess: () => {
            isDeleteModalOpen.value = false;
            projectToDelete.value = null;
        },
    });
};
</script>

<template>
    <Head title="Projects" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                    <h3 class="font-bold text-lg text-gray-900 dark:text-white mb-4">New Project</h3>
                    <form @submit.prevent="submit" class="space-y-4">
                        <input v-model="form.name" placeholder="Project Name" class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white" required />

                        <select v-model="form.client_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white" required>
                            <option value="" disabled>Select Client</option>
                            <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.company_name }}</option>
                        </select>

                        <select v-model="form.document_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white">
                            <option value="">Attach DNA (Optional)</option>
                            <option v-for="doc in documents" :key="doc.id" :value="doc.id">{{ doc.content.substring(0, 40) }}...</option>
                        </select>

                        <textarea v-model="form.description" placeholder="Description" class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white" rows="3"></textarea>

                        <button type="submit" :disabled="form.processing" class="w-full bg-indigo-600 text-white py-2 rounded-lg font-bold hover:bg-indigo-700 transition">
                            Create Project
                        </button>
                    </form>
                </div>

                <div class="lg:col-span-2 space-y-4">
                    <div v-for="project in projects" :key="project.id" class="bg-white dark:bg-gray-800 p-5 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm flex justify-between items-start">
                        <div>
                            <span class="text-[10px] font-bold text-indigo-500 uppercase tracking-widest bg-indigo-50 dark:bg-indigo-900/30 px-2 py-1 rounded">
                                {{ project.client?.company_name }}
                            </span>
                            <h4 class="text-lg font-bold text-gray-900 dark:text-white mt-2">{{ project.name }}</h4>
                            <p class="text-sm text-gray-500 mt-1">{{ project.description }}</p>
                        </div>
                        <button @click="confirmDelete(project)" class="text-red-600 hover:text-red-500 transition">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            :title="`Delete ${projectToDelete?.name}?`"
            description="Are you sure? This will permanently remove this project and its association with the DNA record."
            :loading="deleteForm.processing"
            @close="isDeleteModalOpen = false"
            @confirm="executeDelete"
        />
    </AppLayout>
</template>
