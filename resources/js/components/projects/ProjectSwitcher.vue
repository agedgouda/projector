<script setup lang="ts">
import { ref } from 'vue';
import { Folder, FolderOpen, Plus } from 'lucide-vue-next';
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

// Import the form component
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';

defineProps<{
    projects: Project[];
    currentProject: Project | null;
    // We pass these through so the Switcher stays self-contained
    clients: Client[];
    projectTypes: ProjectType[];
}>();

const emit = defineEmits<{
    (e: 'switch', id: string): void;
}>();

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
                    :icon-fallback="Folder"
                    eyebrow="Active Project"
                    :title="currentProject?.name ?? 'Select Project'"
                />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" class="w-72 rounded-2xl p-2 shadow-xl border-gray-100 dark:border-gray-800">
                <div class="px-3 py-2 text-[9px] font-black uppercase tracking-[0.2em] text-gray-400">
                    Your Portfolio
                </div>

                <DropdownMenuItem v-for="p in projects" :key="p.id" @click="emit('switch', p.id)" class="p-3 cursor-pointer rounded-lg mb-1 flex items-center gap-3">
                    <IconTile :src="p.logo_url" :alt="p.name" :icon="FolderOpen" size="sm" tone="primary" />
                    <span class="font-bold text-sm">{{ p.name }}</span>
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
                    :project-types="projectTypes"
                    @success="handleSuccess"
                    @cancel="isModalOpen = false"
                />
            </DialogContent>
        </Dialog>
    </div>
</template>
