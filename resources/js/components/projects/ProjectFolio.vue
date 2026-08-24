<script setup lang="ts">
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { usePermissions } from '@/composables/usePermissions';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import projectRoutes from '@/routes/projects/index';
import { Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Files,
    FolderTree,
    Pencil,
    Sparkles,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    project: Project;
    showClient?: boolean;
    redirectTo?: string;
    isSubProject?: boolean;
    projects?: {
        id: string;
        name: string;
        client_id: string;
        parent_id?: string | null;
    }[];
    // Position within its list, for alternating-stripe backgrounds — matches
    // OrgUserTable.vue's rule. Omitted by callers (e.g. ClientList.vue) that don't want
    // striping, in which case the row just keeps its plain background.
    rowIndex?: number;
}>();

const { hasRole } = usePermissions();
const canAddSubProject = computed(
    () =>
        !props.isSubProject && (hasRole('super-admin') || hasRole('org-admin')),
);

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
        projectRoutes.destroy.url(String(props.project.id)) +
            '?redirect_to=' +
            encodeURIComponent(props.redirectTo ?? '/projects'),
        {
            preserveState: true,
            onSuccess: () => (isDeleteModalOpen.value = false),
            onFinish: () => (isDeleting.value = false),
        },
    );
};

// The whole row is the click target now (View button removed) — always lands on the Tasks
// tab, mirroring KanbanRow.vue's Dashboard-originated project links, since "click a project
// from a list" always means "show me its tasks," not whichever tab a stale cookie remembers.
const goToProject = () => {
    router.get(
        projectRoutes.show.url(String(props.project.id), {
            query: { tab: 'tasks' },
        }),
    );
};
</script>

<template>
    <div
        v-bind="$attrs"
        class="group/folio grid cursor-pointer grid-cols-[1fr_auto_auto_auto] items-center gap-4 rounded-md px-4 py-3 transition-colors"
        :class="[
            isSubProject ? 'pl-10' : '',
            rowIndex !== undefined && rowIndex % 2 === 1
                ? 'bg-projector-primary-100/70 dark:bg-projector-primary-950/25'
                : '',
            FLAT_ROW_HOVER,
        ]"
        @click="goToProject"
    >
        <div class="flex min-w-0 items-center gap-4">
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-100 shadow-sm dark:border-zinc-700"
                :class="
                    project.logo_url
                        ? 'bg-white'
                        : 'bg-slate-50 dark:bg-zinc-800'
                "
            >
                <img
                    v-if="project.logo_url"
                    :src="project.logo_url"
                    :alt="project.name"
                    class="size-full object-contain"
                />
                <Files v-else class="h-4 w-4 text-gray-400" />
            </div>

            <div class="flex min-w-0 flex-col">
                <div class="flex items-center gap-2">
                    <span
                        class="truncate text-sm font-bold text-slate-900 dark:text-zinc-100"
                    >
                        {{ project.name }}
                    </span>
                    <span
                        v-if="project.inactive"
                        class="shrink-0 rounded border border-slate-200 px-1.5 py-0.5 text-[9px] font-black tracking-widest text-slate-400 uppercase dark:border-zinc-700"
                    >
                        Inactive
                    </span>
                </div>
                <p
                    class="max-w-md truncate text-[11px] text-slate-500 dark:text-zinc-500"
                >
                    {{ project.description || 'No description provided.' }}
                </p>
                <div
                    v-if="project.description && project.description_quality"
                    class="mt-0.5 flex items-center gap-1"
                >
                    <template v-if="project.description_quality === 'good'">
                        <Sparkles class="h-2.5 w-2.5 text-emerald-500" />
                        <span
                            class="text-[9px] font-black tracking-widest text-emerald-500 uppercase"
                            >AI-Enhanced</span
                        >
                    </template>
                    <template
                        v-else-if="project.description_quality === 'vague'"
                    >
                        <AlertTriangle class="h-2.5 w-2.5 text-amber-400" />
                        <span
                            class="text-[9px] font-black tracking-widest text-amber-400 uppercase"
                            >Description too vague for AI context</span
                        >
                    </template>
                </div>
            </div>
        </div>

        <TooltipProvider>
            <div v-if="canAddSubProject" class="flex w-10 justify-end">
                <Tooltip :delay-duration="200">
                    <TooltipTrigger as-child>
                        <Link
                            :href="
                                projectRoutes.create.url({
                                    query: {
                                        client: project.client.company_name,
                                        parent_project: project.id,
                                    },
                                })
                            "
                            class="flex items-center justify-center rounded-lg p-2 text-projector-primary-600 transition-all hover:bg-projector-primary-50 dark:text-projector-primary-400 dark:hover:bg-projector-primary-500/10"
                            @click.stop
                        >
                            <FolderTree class="h-3.5 w-3.5" />
                        </Link>
                    </TooltipTrigger>
                    <TooltipContent>Add Sub-project</TooltipContent>
                </Tooltip>
            </div>

            <div class="flex w-10 justify-end">
                <Tooltip :delay-duration="200">
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            @click.stop="isEditModalOpen = true"
                            class="flex items-center justify-center rounded-lg p-2 text-projector-primary-600 transition-all hover:bg-projector-primary-50 dark:text-projector-primary-400 dark:hover:bg-projector-primary-500/10"
                        >
                            <Pencil class="h-3.5 w-3.5" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>Edit Project</TooltipContent>
                </Tooltip>
            </div>

            <div class="flex w-10 justify-end">
                <Tooltip :delay-duration="200">
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            @click.stop="isDeleteModalOpen = true"
                            class="flex items-center justify-center rounded-lg p-2 text-projector-primary-600 transition-all hover:bg-projector-primary-50 dark:text-projector-primary-400 dark:hover:bg-projector-primary-500/10"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>Delete Project</TooltipContent>
                </Tooltip>
            </div>
        </TooltipProvider>

        <Dialog v-model:open="isEditModalOpen">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Edit Project</DialogTitle>
                    <DialogDescription>
                        Update the name and description for
                        <strong>{{ project.name }}</strong
                        >.
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
