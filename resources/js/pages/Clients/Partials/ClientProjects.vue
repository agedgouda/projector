<script setup lang="ts">
import { ref, computed } from 'vue';
import ProjectFolio from '@/components/projects/ProjectFolio.vue';
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';
import { Search, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";

const props = defineProps<{
    client: { id: string | number, company_name: string };
    projects: Project[];
    projectTypes: ProjectType[];
}>();

// --- State ---
const searchQuery = ref('');
const isProjectModalOpen = ref(false);

const handleSuccess = (_clientId: string) => {
    isProjectModalOpen.value = false;
};

// --- Filter & Sort Logic ---
const filteredProjects = computed(() => {
    let list = [...props.projects];

    if (searchQuery.value.trim()) {
        const query = searchQuery.value.toLowerCase();
        list = list.filter(p => p.name.toLowerCase().includes(query));
    }

    return list.sort((a, b) => a.name.localeCompare(b.name));
});
</script>

<template>
    <div class="mt-8 space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">

        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h3 class="font-bold text-gray-500 text-sm uppercase tracking-widest">Active Projects</h3>
                <p class="text-xs text-gray-400">Showing {{ filteredProjects.length }} records for {{ client.company_name }}</p>
            </div>

            <div class="flex items-center gap-3 w-full md:w-auto">
                <div class="relative flex-1 md:w-64 group">
                    <Search :class="FLAT_SEARCH_ICON" />
                    <Input
                        v-model="searchQuery"
                        placeholder="Filter projects..."
                        :class="FLAT_SEARCH_INPUT"
                    />
                </div>

                <Dialog v-model:open="isProjectModalOpen">
                    <DialogTrigger asChild>
                        <Button variant="outline" class="border-projector-primary-200 dark:border-projector-primary-900/50 text-projector-primary-600 dark:text-projector-primary-400 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-900/20 font-bold rounded-xl">
                            <Plus class="w-4 h-4 mr-2" />
                            Add Project
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-[425px]">
                        <DialogHeader>
                            <DialogTitle>Add Project</DialogTitle>
                            <DialogDescription>
                                Create a new project for {{ client.company_name }}.
                            </DialogDescription>
                        </DialogHeader>
                        <ProjectEntryForm
                            :client="(client as Client)"
                            :projectTypes="projectTypes"
                            @success="handleSuccess"
                        />
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <div class="relative w-full">
            <div v-if="filteredProjects.length === 0" class="text-center py-16 border-2 border-dashed rounded-2xl border-gray-100 dark:border-gray-800 text-gray-400 text-sm">
                {{ searchQuery ? `No results for "${searchQuery}"` : `No active records found.` }}
            </div>

            <TransitionGroup
                name="list"
                tag="div"
                class="space-y-3 relative w-full"
            >
                <div v-for="project in filteredProjects" :key="project.id" class="w-full">
                    <ProjectFolio :project="project" class="w-full" />
                </div>
            </TransitionGroup>
        </div>
    </div>
</template>

