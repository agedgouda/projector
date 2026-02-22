<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { updateLifecycleStep } from '@/actions/App/Http/Controllers/ProjectController';

const props = defineProps<{
    steps: LifecycleStep[];
    currentStepId: number | null;
    projectId: string;
}>();

const colorMap: Record<string, string> = {
    indigo: 'bg-indigo-500',
    blue: 'bg-blue-500',
    green: 'bg-green-500',
    amber: 'bg-amber-500',
    orange: 'bg-orange-500',
    red: 'bg-red-500',
    purple: 'bg-purple-500',
    pink: 'bg-pink-500',
    slate: 'bg-slate-500',
};

const currentIndex = computed(() => props.steps.findIndex(s => s.id === props.currentStepId));

const selectStep = (stepId: number) => {
    router.patch(
        updateLifecycleStep.url(props.projectId),
        { current_lifecycle_step_id: stepId },
        { preserveScroll: true },
    );
};
</script>

<template>
    <div class="w-full bg-white dark:bg-gray-950 border border-gray-100 dark:border-gray-800 rounded-2xl px-6 py-4 shadow-sm">
        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 mb-3">Lifecycle</p>

        <div class="flex items-center gap-0">
            <template v-for="(step, index) in steps" :key="step.id">
                <button
                    type="button"
                    @click="selectStep(step.id!)"
                    class="flex flex-col items-center group relative"
                >
                    <div
                        class="w-4 h-4 rounded-full border-2 transition-all"
                        :class="[
                            index < currentIndex
                                ? (step.color ? colorMap[step.color] + ' border-transparent opacity-50' : 'bg-gray-300 border-transparent')
                                : index === currentIndex
                                    ? (step.color ? colorMap[step.color] + ' border-transparent ring-4 ring-offset-2 ring-indigo-200 dark:ring-indigo-900 scale-125' : 'bg-gray-400 border-transparent ring-4 ring-offset-2 ring-gray-200')
                                    : 'bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-700 group-hover:border-gray-400 dark:group-hover:border-gray-500',
                        ]"
                    />
                    <span
                        class="mt-2 text-[10px] font-bold whitespace-nowrap transition-colors max-w-20 text-center leading-tight"
                        :class="[
                            index === currentIndex
                                ? 'text-gray-900 dark:text-white'
                                : index < currentIndex
                                    ? 'text-gray-400 dark:text-gray-500'
                                    : 'text-gray-300 dark:text-gray-600 group-hover:text-gray-500 dark:group-hover:text-gray-400',
                        ]"
                    >
                        {{ step.label }}
                    </span>
                </button>

                <div
                    v-if="index < steps.length - 1"
                    class="h-[2px] flex-1 min-w-4 mb-5 mx-1 transition-colors"
                    :class="index < currentIndex ? 'bg-gray-300 dark:bg-gray-600' : 'bg-gray-100 dark:bg-gray-800'"
                />
            </template>
        </div>
    </div>
</template>
