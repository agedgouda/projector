<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Search, Files, Trash2, Pencil, Sparkles, AlertTriangle, FolderTree } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps<{
    project: Project
    showClient?: boolean;
    redirectTo?: string;
    isSubProject?: boolean;
    projects?: { id: string; name: string; client_id: string; parent_id?: string | null }[];
    // Position within its list, for alternating-stripe backgrounds — matches
    // OrgUserTable.vue's rule. Omitted by callers (e.g. ClientList.vue) that don't want
    // striping, in which case the row just keeps its plain background.
    rowIndex?: number;
}>();

const { hasRole } = usePermissions();
const canAddSubProject = computed(() => !props.isSubProject && (hasRole('super-admin') || hasRole('org-admin')));

// --- EDIT STATE ---
const isEditModalOpen = ref(false);

const handleEditSuccess = () => {
    isEditModalOpen.value = false;
};

// --- DELETE STATE ---
const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);

const executeDelete = () => {
    isDeleting.value = true;
    router.delete(
        projectRoutes.destroy.url(String(props.project.id)) + '?redirect_to=' + encodeURIComponent(props.redirectTo ?? '/projects'),
        {
            preserveState: true,
            onSuccess: () => (isDeleteModalOpen.value = false),
            onFinish: () => (isDeleting.value = false),
        }
    );
};

</script>

<template>
    <div
        v-bind="$attrs"
        class="group/folio grid grid-cols-[1fr_auto_auto_auto_auto] items-center gap-4 py-3 px-4 rounded-md transition-colors"
        :class="[
            isSubProject ? 'pl-10' : '',
            rowIndex !== undefined && rowIndex % 2 === 1 ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25' : '',
        ]"
    >

        <div class="flex items-center gap-4 min-w-0">
            <div class="h-9 w-9 shrink-0 rounded-lg bg-slate-50 dark:bg-zinc-800 flex items-center justify-center border border-slate-100 dark:border-zinc-700 shadow-sm overflow-hidden">
                <img v-if="project.logo_url" :src="project.logo_url" :alt="project.name" class="size-full object-contain" />
                <Files v-else class="w-4 h-4 text-gray-400" />
            </div>

            <div class="flex flex-col min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-sm text-slate-900 dark:text-zinc-100 truncate">
                        {{ project.name }}
                    </span>
                    <span v-if="project.inactive" class="shrink-0 text-[9px] font-black uppercase tracking-widest text-slate-400 border border-slate-200 dark:border-zinc-700 px-1.5 py-0.5 rounded">
                        Inactive
                    </span>
                </div>
                <p class="text-[11px] text-slate-500 dark:text-zinc-500 truncate max-w-md">
                    {{ project.description || 'No description provided.' }}
                </p>
                <div v-if="project.description && project.description_quality" class="flex items-center gap-1 mt-0.5">
                    <template v-if="project.description_quality === 'good'">
                        <Sparkles class="w-2.5 h-2.5 text-emerald-500" />
                        <span class="text-[9px] font-black uppercase tracking-widest text-emerald-500">AI-Enhanced</span>
                    </template>
                    <template v-else-if="project.description_quality === 'vague'">
                        <AlertTriangle class="w-2.5 h-2.5 text-amber-400" />
                        <span class="text-[9px] font-black uppercase tracking-widest text-amber-400">Description too vague for AI context</span>
                    </template>
                </div>
            </div>
        </div>

        <div class="flex justify-end min-w-[120px]">
            <Link
                :href="projectRoutes.show.url(String(project.id))"
                class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest text-projector-primary-600 dark:text-projector-primary-400 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-500/10 transition-all group/btn"
            >
                <Search class="w-3.5 h-3.5" />
                View
            </Link>
        </div>

        <div class="flex justify-end w-10">
            <Link
                v-if="canAddSubProject"
                :href="projectRoutes.create.url({ query: { client: project.client.company_name, parent_project: project.id } })"
                class="flex items-center justify-center p-2 rounded-lg text-projector-primary-600 dark:text-projector-primary-400 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-500/10 transition-all"
                title="Add Sub-project"
            >
                <FolderTree class="w-3.5 h-3.5" />
            </Link>
        </div>

        <div class="flex justify-end w-10">
            <button
                @click="isEditModalOpen = true"
                class="p-2 text-slate-300 hover:text-projector-primary-500 transition-colors"
                title="Edit Project"
            >
                <Pencil class="w-4 h-4" />
            </button>
        </div>

        <div class="flex justify-end w-10">
            <button
                @click="isDeleteModalOpen = true"
                class="p-2 text-slate-300 hover:text-red-500 transition-colors"
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
                    :projects="projects"
                    @success="handleEditSuccess"
                    @cancel="isEditModalOpen = false"
                />
            </DialogContent>
        </Dialog>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            :title="`Delete ${project.name}`"
            description="Are you sure you want to delete this project? This action cannot be undone."
            :loading="isDeleting"
            @close="isDeleteModalOpen = false"
            @confirm="executeDelete"
        />
    </div>
</template>
