<script setup lang="ts">
import { Button } from '@/components/ui/button';
import ProjectIcon from '@/components/ProjectIcon.vue';
import { ChevronLeft } from 'lucide-vue-next';

defineProps<{
    project: Project;
    origin: string | null;
    activeTab: string;
    backLabel: string; // Passed from composable via Show.vue
}>();

const emit = defineEmits<{
    (e: 'update:activeTab', tab: string): void;
    (e: 'edit'): void;
    (e: 'back'): void;
}>();
</script>

<template>
    <button @click="emit('back')" class="inline-flex items-center text-sm text-gray-500 hover:text-indigo-600 transition-colors mb-6 group">
        <ChevronLeft class="w-4 h-4 mr-1 group-hover:-translate-x-1 transition-transform" />
        {{ backLabel }}
    </button>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-8 p-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="flex items-start md:items-center gap-5">
                <div class="p-3.5 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl text-indigo-600">
                    <ProjectIcon :name="project.type?.icon" class="w-9 h-9" />
                </div>
                <div>
                    <h1 class="text-3xl font-black text-gray-900 dark:text-white tracking-tight">{{ project.name }}</h1>
                    <p class="text-sm text-gray-500 uppercase text-[10px] font-bold tracking-wider">
                        Client: <span class="text-gray-700 dark:text-gray-200">{{ project.client?.company_name }}</span>
                    </p>
                </div>
            </div>
            <Button @click="emit('edit')" variant="outline" class="font-bold text-xs uppercase tracking-widest px-6">
                Edit Project
            </Button>
        </div>
        <div class="flex items-start mt-3 md:items-center gap-5">
             <h2 class="italic text-gray-900 dark:text-white tracking-tight">{{ project.description }}</h2>
        </div>
    </div>

    <div class="flex items-center border-b border-gray-200 dark:border-gray-700 mb-6">
        <button v-for="tab in ['docs', 'tasks']" :key="tab"
            @click="emit('update:activeTab', tab)"
            :class="['px-8 py-4 text-[10px] font-black uppercase tracking-[0.2em] transition-all border-b-2 -mb-[1px]',
                activeTab === tab ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-400 hover:text-gray-600']">
            {{ tab === 'docs' ? 'Documentation' : 'Tasks' }}
        </button>
    </div>
</template>
