<script setup lang="ts">
import { computed, ref } from 'vue';
import { Folders, FolderOpen, Plus, Star } from 'lucide-vue-next';
import FlatSwitcherTrigger from '@/components/FlatSwitcherTrigger.vue';
import IconTile from '@/components/IconTile.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    DropdownMenuSeparator
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    toggleProjectFavorite,
    useFavoriteProjectIds,
} from '@/composables/useFavoriteProject';

// Import the form component
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';

const props = defineProps<{
    projects: Project[];
    currentProject: Project | null;
    // We pass this through so the Switcher stays self-contained
    clients: Client[];
}>();

// Sub-projects are listed directly beneath their parent, indented — rather than interleaved
// with everything else in creation-date order. A child whose parent isn't in this (active,
// visible-to-user) list at all falls back to rendering at the top level, unindented, so it's
// never silently dropped from the switcher.
const groupedProjects = computed(() => {
    const ids = new Set(props.projects.map((p) => p.id));
    const childrenByParentId = new Map<string, Project[]>();

    props.projects.forEach((project) => {
        if (!project.parent_id || !ids.has(project.parent_id)) return;
        const siblings = childrenByParentId.get(project.parent_id) ?? [];
        siblings.push(project);
        childrenByParentId.set(project.parent_id, siblings);
    });

    const entries: { project: Project; isSubProject: boolean }[] = [];

    props.projects
        .filter((project) => !project.parent_id || !ids.has(project.parent_id))
        .forEach((project) => {
            entries.push({ project, isSubProject: false });
            (childrenByParentId.get(project.id) ?? []).forEach((child) => {
                entries.push({ project: child, isSubProject: true });
            });
        });

    return entries;
});

const emit = defineEmits<{
    (e: 'switch', id: string): void;
}>();

// --- FAVORITE STATE ---
const favoriteProjectIds = useFavoriteProjectIds();
const isFavorited = (projectId: string) => favoriteProjectIds.value.has(projectId);
const toggleFavorite = (projectId: string) =>
    toggleProjectFavorite(projectId, isFavorited(projectId));

const isModalOpen = ref(false);

const handleSuccess = () => {
    isModalOpen.value = false;
};
</script>

<template>
    <div class="flex items-center">
        <DropdownMenu v-if="projects.length > 0">
            <DropdownMenuTrigger as-child>
                <FlatSwitcherTrigger
                    :icon-src="currentProject?.logo_url"
                    :icon-alt="currentProject?.name"
                    :icon-fallback="Folders"
                    eyebrow="Active Project"
                    :title="currentProject?.name ?? 'Select Project'"
                />
            </DropdownMenuTrigger>

            <!-- Sibling, not nested inside FlatSwitcherTrigger's <template #trailing> slot —
                 Reka UI's DropdownMenuTrigger as-child clones props onto FlatSwitcherTrigger's
                 root but doesn't forward the slotted content through, so a slot there silently
                 renders nothing. -->
            <button
                v-if="currentProject"
                type="button"
                :title="
                    isFavorited(currentProject.id)
                        ? 'Remove from favorites'
                        : 'Add to favorites'
                "
                class="flex shrink-0 items-center justify-center rounded-lg p-1.5 transition-colors"
                :class="
                    isFavorited(currentProject.id)
                        ? 'text-projector-highlight-500 hover:text-projector-highlight-600'
                        : 'text-slate-300 hover:text-projector-highlight-400 dark:text-zinc-600'
                "
                @click="toggleFavorite(currentProject.id)"
            >
                <Star
                    class="h-4 w-4"
                    :class="isFavorited(currentProject.id) ? 'fill-current' : ''"
                />
            </button>

            <DropdownMenuContent align="start" class="w-72 rounded-2xl p-2 shadow-xl border-gray-100 dark:border-gray-800">
                <div class="px-3 py-2 text-[9px] font-black uppercase tracking-[0.2em] text-gray-400">
                    Your Portfolio
                </div>

                <DropdownMenuItem
                    v-for="entry in groupedProjects"
                    :key="entry.project.id"
                    @click="emit('switch', entry.project.id)"
                    class="p-3 cursor-pointer rounded-lg mb-1 flex items-center gap-3"
                    :class="{ 'pl-10': entry.isSubProject }"
                >
                    <span
                        role="button"
                        tabindex="0"
                        :title="
                            isFavorited(entry.project.id)
                                ? 'Remove from favorites'
                                : 'Add to favorites'
                        "
                        class="flex shrink-0 items-center justify-center rounded p-0.5 transition-colors"
                        :class="
                            isFavorited(entry.project.id)
                                ? 'text-projector-highlight-500 hover:text-projector-highlight-600'
                                : 'text-slate-300 hover:text-projector-highlight-400 dark:text-zinc-600'
                        "
                        @click.stop="toggleFavorite(entry.project.id)"
                        @keydown.enter.stop="toggleFavorite(entry.project.id)"
                        @keydown.space.stop.prevent="
                            toggleFavorite(entry.project.id)
                        "
                    >
                        <Star
                            class="h-3.5 w-3.5"
                            :class="isFavorited(entry.project.id) ? 'fill-current' : ''"
                        />
                    </span>
                    <IconTile :src="entry.project.logo_url" :alt="entry.project.name" :icon="FolderOpen" size="sm" tone="primary" />
                    <span class="font-bold text-sm">{{ entry.project.name }}</span>
                </DropdownMenuItem>

                <DropdownMenuSeparator class="my-2 bg-gray-100 dark:bg-gray-800" />

                <DropdownMenuItem @click="isModalOpen = true" class="p-3 cursor-pointer rounded-lg text-projector-primary-600 hover:text-projector-primary-700 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-950/30 flex items-center gap-2">
                    <div class="w-6 h-6 rounded-md bg-projector-primary-50 dark:bg-projector-primary-900/50 flex items-center justify-center">
                        <Plus class="w-3.5 h-3.5" />
                    </div>
                    <span class="font-black uppercase text-[10px] tracking-widest">New Project</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <Dialog v-model:open="isModalOpen">
            <DialogContent class="sm:max-w-[500px] rounded-[2.5rem] border-none shadow-2xl bg-white dark:bg-gray-950">
                <DialogHeader class="pt-4 px-2">
                    <DialogTitle class="text-xl font-black uppercase tracking-tight">Initialize Project</DialogTitle>
                    <DialogDescription class="text-xs font-bold text-gray-400 uppercase tracking-widest">
                        New Workspace Setup
                    </DialogDescription>
                </DialogHeader>

                <ProjectEntryForm
                    :clients="clients"
                    @success="handleSuccess"
                    @cancel="isModalOpen = false"
                />
            </DialogContent>
        </Dialog>
    </div>
</template>
