<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { Search, FolderOpen, Trash2, Pencil } from 'lucide-vue-next';
import { ref, computed } from 'vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import projectRoutes from '@/routes/projects/index';
import { dashboard } from '@/routes/';

const props = defineProps<{
    project: Project
    showClient?: boolean;
}>();

const page = usePage();

// --- EDIT STATE ---
const isEditModalOpen = ref(false);

const handleEditSuccess = () => {
    isEditModalOpen.value = false;
};

// --- DELETE STATE ---
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
    return `${dashboard().url}?project=${props.project.id}&from=${origin}`;
});
</script>

<template>
    <div v-bind="$attrs" class="group/folio grid grid-cols-[1fr_auto_auto_auto] items-center gap-4 py-3 px-4 transition-colors hover:bg-gray-50/50 dark:hover:bg-zinc-800/30">

        <div class="flex items-center gap-4 min-w-0">
            <div class="h-9 w-9 shrink-0 rounded-lg bg-slate-50 dark:bg-zinc-800 flex items-center justify-center border border-slate-100 dark:border-zinc-700 shadow-sm">
                <div v-if="project.type" class="text-indigo-600 dark:text-indigo-400">
                    <ProjectIcon :name="project.type.icon" size="18" />
                </div>
                <FolderOpen v-else class="w-4 h-4 text-gray-400" />
            </div>

            <div class="flex flex-col min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-sm text-slate-900 dark:text-zinc-100 truncate">
                        {{ project.name }}
                    </span>
                </div>
                <p class="text-[11px] text-slate-500 dark:text-zinc-500 truncate max-w-md">
                    <span class="font-black uppercase tracking-tighter text-[9px] opacity-70">
                        {{ project.type?.name || 'General' }}
                    </span>:
                    {{ project.description || 'No description provided.' }}
                </p>
            </div>
        </div>

        <div class="flex justify-end min-w-[120px]">
            <Link
                :href="projectLink"
                class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 transition-all group/btn"
            >
                <Search class="w-3.5 h-3.5" />
                View
            </Link>
        </div>

        <div class="flex justify-end w-10">
            <button
                @click="isEditModalOpen = true"
                class="p-2 text-slate-300 hover:text-indigo-500 transition-colors opacity-0 group-hover/folio:opacity-100"
                title="Edit Project"
            >
                <Pencil class="w-4 h-4" />
            </button>
        </div>

        <div class="flex justify-end w-10">
            <button
                @click="isDeleteModalOpen = true"
                class="p-2 text-slate-300 hover:text-red-500 transition-colors opacity-0 group-hover/folio:opacity-100"
                title="Delete Project"
            >
                <Trash2 class="w-4 h-4" />
            </button>
        </div>

        <Dialog v-model:open="isEditModalOpen">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Edit Project</DialogTitle>
                    <DialogDescription>
                        Update the name and description for <strong>{{ project.name }}</strong>.
                    </DialogDescription>
                </DialogHeader>

                <ProjectEntryForm
                    :edit-data="project"
                    @success="handleEditSuccess"
                    @cancel="isEditModalOpen = false"
                />
            </DialogContent>
        </Dialog>

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
