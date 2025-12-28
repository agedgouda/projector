<script setup lang="ts">
import { ref, computed } from 'vue';
import ProjectFolio from '@/components/ProjectFolio.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import { Search, X } from 'lucide-vue-next';

const props = defineProps<{
    client: { id: string | number, company_name: string };
    projects: any[];
    projectTypes: any[];
}>();

// --- Search State ---
const searchQuery = ref('');

// --- Filter & Sort Logic ---
const filteredProjects = computed(() => {
    let list = [...props.projects];

    // 1. Filter by Name
    if (searchQuery.value.trim()) {
        const query = searchQuery.value.toLowerCase();
        list = list.filter(p => p.name.toLowerCase().includes(query));
    }

    // 2. Sort by Name
    return list.sort((a, b) => a.name.localeCompare(b.name));
});
</script>

<template>
    <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6 animate-in fade-in slide-in-from-bottom-4 duration-500">

        <div class="bg-indigo-50 dark:bg-indigo-900/10 p-6 rounded-xl border border-indigo-100 dark:border-indigo-900/30 h-fit">
            <h3 class="font-bold text-indigo-900 dark:text-indigo-300 mb-4 text-sm uppercase tracking-wider">
                Add Project to {{ client.company_name }}
            </h3>

            <ProjectForm
                :initialClientId="client.id"
                :projectTypes="projectTypes"
            />
        </div>

        <div class="lg:col-span-2 space-y-4">

            <div class="flex items-center justify-between gap-4 mb-2">
                <h3 class="font-bold text-gray-500 text-sm uppercase tracking-wider">Active Projects</h3>

                <div class="relative w-64 group">
                    <Search class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 group-focus-within:text-indigo-500 transition-colors" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Filter by name..."
                        class="block w-full pl-8 pr-8 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                    />
                    <button v-if="searchQuery" @click="searchQuery = ''" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <X class="w-3.5 h-3.5" />
                    </button>
                </div>
            </div>

            <div v-if="filteredProjects.length === 0" class="text-center py-10 border-2 border-dashed rounded-xl border-gray-200 dark:border-gray-800 text-gray-400 text-sm">
                {{ searchQuery ? `No results for "${searchQuery}"` : `No active records for ${client.company_name}.` }}
            </div>

            <TransitionGroup
                name="list"
                tag="div"
                class="space-y-3 relative"
            >
                <div v-for="project in filteredProjects" :key="project.id">
                    <ProjectFolio :project="project" />
                </div>
            </TransitionGroup>
        </div>
    </div>
</template>

<style scoped>
.list-enter-active,
.list-leave-active {
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.list-enter-from {
    opacity: 0;
    transform: translateX(20px);
}

.list-leave-to {
    opacity: 0;
    transform: translateX(-20px);
}

.list-move {
    transition: transform 0.4s ease;
}

.list-leave-active {
    position: absolute;
    width: 100%;
}
</style>
