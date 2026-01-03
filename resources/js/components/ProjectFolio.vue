<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { ref, computed } from 'vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import projectRoutes from '@/routes/projects/index';

// 1. Assign defineProps to a variable so we can use it in script
const props = defineProps<{
    project: {
        id: string | number;
        name: string;
        description?: string;
        type?: {
            name: string;
            icon: string;
        };
        client?: { id: string | number; company_name: string; };
    };
    showClient?: boolean;
}>();

const page = usePage();

// Delete Modal State
const isDeleteModalOpen = ref(false);
const projectToDelete = ref<{ id: string | number; name: string } | null>(null);
const deleteForm = useForm({});

const confirmDelete = (project: any) => {
    projectToDelete.value = { id: project.id, name: project.name };
    isDeleteModalOpen.value = true;
};

const executeDelete = () => {
    if (!projectToDelete.value) return;

    const projectId = String(projectToDelete.value.id);

    deleteForm.delete(projectRoutes.destroy.url(projectId), {
        onSuccess: () => {
            isDeleteModalOpen.value = false;
            projectToDelete.value = null;
        },
    });
};

// 2. Fixed projectLink logic
const projectLink = computed(() => {
    const isClient = page.url.startsWith('/clients');
    const isIndex = page.url.startsWith('/projects');

    // Use isIndex and isClient to determine the origin tag
    let origin = 'index';
    if (isClient) {
        origin = 'client';
    } else if (isIndex) {
        origin = 'index';
    }

    return `/projects/${props.project.id}?from=${origin}`;
});
</script>

<template>
    <div v-bind="$attrs">
    <div class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 flex justify-between items-center shadow-sm hover:border-indigo-300 dark:hover:border-indigo-800 transition-colors group">

        <div class="min-w-0 flex-1 mr-4">
            <div class="flex items-center gap-2 flex-wrap">
                <div v-if="project.type" class="text-indigo-600 dark:text-indigo-400">
                    <ProjectIcon :name="project.type.icon" size="16" />
                </div>

                <h4 class="font-bold text-gray-900 dark:text-white truncate">
                    {{ project.name }}
                </h4>

                <span v-if="project.client" class="text-xs text-gray-400 font-medium">
                    for {{ project.client.company_name }}
                </span>

                <span
                    v-if="project.type"
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800"
                >
                    {{ project.type.name }}
                </span>
            </div>

            <p class="text-xs text-gray-500 mt-1 line-clamp-1">
                {{ project.description || 'No description provided.' }}
            </p>
        </div>

        <div class="flex items-center gap-4 shrink-0">
            <Link
                :href="projectLink"
                class="flex items-center gap-1.5 text-sm font-bold text-indigo-600 hover:text-indigo-800 transition-colors whitespace-nowrap group/link"
            >
                <Search class="w-4 h-4 transition-transform group-hover/link:scale-110" />
                Details
            </Link>

            <button
                @click="confirmDelete(project)"
                class="text-gray-300 hover:text-red-500 transition-colors p-1"
                title="Delete Project"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>
    </div>

    <ConfirmDeleteModal
        :open="isDeleteModalOpen"
        :title="`Delete ${projectToDelete?.name}`"
        description="Are you sure you want to delete this project? This action cannot be undone."
        :loading="deleteForm.processing"
        @close="isDeleteModalOpen = false"
        @confirm="executeDelete"
    />
    </div>
</template>
