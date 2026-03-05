<script setup lang="ts">
import { ref } from 'vue';
import { Folder, ChevronDown, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
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
                <Button variant="ghost" class="h-14 px-5 rounded-2xl bg-white dark:bg-gray-950 border border-gray-100 dark:border-gray-800 flex items-center gap-4 hover:bg-gray-50 dark:hover:bg-gray-900 transition-all shadow-sm">
                    <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white">
                        <Folder class="w-5 h-5" />
                    </div>
                    <div class="text-left">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 leading-none mb-1.5">Active Project</p>
                        <p class="text-base font-bold text-gray-900 dark:text-white">{{ currentProject?.name ?? 'Select Project' }}</p>
                    </div>
                    <ChevronDown class="w-4 h-4 text-gray-300 ml-2" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" class="w-72 rounded-2xl p-2 shadow-xl border-gray-100 dark:border-gray-800">
                <div class="px-3 py-2 text-[9px] font-black uppercase tracking-[0.2em] text-gray-400">
                    Your Portfolio
                </div>

                <DropdownMenuItem v-for="p in projects" :key="p.id" @click="emit('switch', p.id)" class="p-3 cursor-pointer rounded-lg mb-1">
                    <span class="font-bold text-sm">{{ p.name }}</span>
                </DropdownMenuItem>

                <DropdownMenuSeparator class="my-2 bg-gray-100 dark:bg-gray-800" />

                <DropdownMenuItem @click="isModalOpen = true" class="p-3 cursor-pointer rounded-lg text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 flex items-center gap-2">
                    <div class="w-6 h-6 rounded-md bg-indigo-50 dark:bg-indigo-900/50 flex items-center justify-center">
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
