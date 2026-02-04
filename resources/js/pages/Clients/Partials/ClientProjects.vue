<script setup lang="ts">
import { ref, computed } from 'vue';
import ProjectFolio from '@/components/projects/ProjectFolio.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import { Search, X, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
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
    projects: any[];
    projectTypes: any[];
}>();

// --- State ---
const searchQuery = ref('');
const isProjectModalOpen = ref(false);

const handleSuccess = () => {
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
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 group-focus-within:text-indigo-500 transition-colors" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Filter projects..."
                        class="block w-full pl-9 pr-9 py-2 text-sm border border-gray-200 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-950 focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all shadow-sm"
                    />
                    <button v-if="searchQuery" @click="searchQuery = ''" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <X class="w-4 h-4" />
                    </button>
                </div>

                <Dialog v-model:open="isProjectModalOpen">
                    <DialogTrigger asChild>
                        <Button variant="outline" class="border-indigo-200 dark:border-indigo-900/50 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 font-bold rounded-xl">
                            <Plus class="w-4 h-4 mr-2" />
                            Add Project
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-[425px]">
                        <DialogHeader>
                            <DialogTitle>Add Project</DialogTitle>
                            <DialogDescription>
                                Create a new project record for {{ client.company_name }}.
                            </DialogDescription>
                        </DialogHeader>
                        <ProjectForm
                            :initialClientId="client.id"
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

