<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { updateLifecycleStep } from '@/actions/App/Http/Controllers/ProjectController';

const props = defineProps<{
    project: Project;
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

const steps = computed(() => props.project.type?.lifecycle_steps ?? []);
const currentStep = computed(() => props.project.current_lifecycle_step ?? null);

const selectStep = (stepId: number | null) => {
    router.patch(
        updateLifecycleStep.url(props.project.id),
        { current_lifecycle_step_id: stepId },
        { preserveScroll: true, preserveState: true },
    );
};
</script>

<template>
    <DropdownMenu v-if="steps.length > 0">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="h-14 px-5 rounded-2xl bg-white dark:bg-gray-950 border border-gray-100 dark:border-gray-800 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-900 transition-all shadow-sm"
            >
                <div
                    class="w-2.5 h-2.5 rounded-full shrink-0"
                    :class="currentStep?.color ? colorMap[currentStep.color] ?? 'bg-slate-400' : 'bg-gray-200 dark:bg-gray-700'"
                />
                <div class="text-left">
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 leading-none mb-1.5">Stage</p>
                    <p class="text-sm font-bold text-gray-900 dark:text-white">
                        {{ currentStep?.label ?? 'Set Stage' }}
                    </p>
                </div>
                <ChevronDown class="w-4 h-4 text-gray-300 ml-1" />
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="start" class="w-56 rounded-2xl p-2 shadow-xl border-gray-100 dark:border-gray-800">
            <div class="px-3 py-2 text-[9px] font-black uppercase tracking-[0.2em] text-gray-400">
                Lifecycle Stage
            </div>

            <DropdownMenuItem
                v-for="step in steps"
                :key="step.id"
                @click="selectStep(step.id!)"
                class="p-3 cursor-pointer rounded-lg mb-0.5 flex items-center gap-2.5"
                :class="{ 'bg-indigo-50 dark:bg-indigo-950/30': currentStep?.id === step.id }"
            >
                <div
                    class="w-2 h-2 rounded-full shrink-0"
                    :class="step.color ? colorMap[step.color] ?? 'bg-slate-400' : 'bg-gray-300'"
                />
                <span class="font-bold text-sm">{{ step.label }}</span>
            </DropdownMenuItem>

            <DropdownMenuItem
                v-if="currentStep"
                @click="selectStep(null)"
                class="p-3 cursor-pointer rounded-lg text-gray-400 hover:text-gray-600 text-xs font-bold uppercase tracking-widest mt-1 border-t border-gray-100 dark:border-gray-800"
            >
                Clear Stage
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
