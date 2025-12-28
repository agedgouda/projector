<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import ProjectIcon from '@/components/ProjectIcon.vue';

defineProps<{
    project: {
        id: string | number;
        name: string;
        description?: string;
        type?: {
            name: string;
            icon: string;
        }
    }
}>();
</script>

<template>
    <div class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 flex justify-between items-center shadow-sm hover:border-indigo-300 dark:hover:border-indigo-800 transition-colors">
        <div class="min-w-0 flex-1 mr-4">
            <div class="flex items-center gap-2 flex-wrap">
                <div v-if="project.type" class="text-indigo-600 dark:text-indigo-400">
                    <ProjectIcon :name="project.type.icon" size="16" />
                </div>

                <h4 class="font-bold text-gray-900 dark:text-white truncate">
                    {{ project.name }}
                </h4>

                <span
                    v-if="project.type"
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800"
                >
                    {{ project.type.name }}
                </span>
            </div>

            <p class="text-xs text-gray-500 mt-1 line-clamp-1">
                {{ project.description || 'No description provided.' }}
            </p>
        </div>

        <Link
            :href="`/projects/${project.id}`"
            class="flex items-center gap-1.5 text-sm font-bold text-indigo-600 hover:text-indigo-800 transition-colors whitespace-nowrap group shrink-0"
        >
            <Search class="w-4 h-4 transition-transform group-hover:scale-110" />
            Details
        </Link>
    </div>
</template>
