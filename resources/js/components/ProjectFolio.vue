<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { Search, FolderOpen } from 'lucide-vue-next';
import { ref, computed } from 'vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ResourceCard from '@/components/ResourceCard.vue'; // The new base
import projectRoutes from '@/routes/projects/index';

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
const deleteForm = useForm({});

const executeDelete = () => {
    deleteForm.delete(projectRoutes.destroy.url(String(props.project.id)), {
        onSuccess: () => (isDeleteModalOpen.value = false),
    });
};

const projectLink = computed(() => {
    const isClient = page.url.startsWith('/clients');
    const origin = isClient ? 'client' : 'index';
    return `/projects/${props.project.id}?from=${origin}`;
});
</script>

<template>
    <div v-bind="$attrs">
        <ResourceCard
            :title="project.name"
            :description="project.description || 'No description provided.'"
            :pillText="project.type?.name"
            :show-delete="true"
            @delete="isDeleteModalOpen = true"
        >
            <template #icon>
                <div v-if="project.type" class="text-indigo-600 dark:text-indigo-400">
                    <ProjectIcon :name="project.type.icon" size="18" />
                </div>
                <FolderOpen v-else class="w-4 h-4 text-gray-400" />

                <span v-if="project.client" class="text-xs text-gray-400 font-medium ml-1">
                    for {{ project.client.company_name }}
                </span>
            </template>

            <template #actions>
                <Link
                    :href="projectLink"
                    class="flex items-center gap-1.5 text-sm font-bold text-indigo-600 hover:text-indigo-800 transition-colors whitespace-nowrap group/link mr-2"
                >
                    <Search class="w-4 h-4 transition-transform group-hover/link:scale-110" />
                    Details
                </Link>
            </template>
        </ResourceCard>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            :title="`Delete ${project.name}`"
            description="Are you sure you want to delete this project? This action cannot be undone."
            :loading="deleteForm.processing"
            @close="isDeleteModalOpen = false"
            @confirm="executeDelete"
        />
    </div>
</template>
