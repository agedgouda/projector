<script setup lang="ts">
import { ref } from 'vue';
import { ChevronDown, ChevronUp, Link as LinkIcon, MessageSquare } from 'lucide-vue-next';
import {
    Tooltip, TooltipContent, TooltipProvider, TooltipTrigger
} from '@/components/ui/tooltip';
import { PRIORITY_LABELS, STATUS_LABELS,priorityClasses, statusClasses, statusDotClasses } from '@/lib/constants';
import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/vue3';
import { formatDate } from '@/lib/utils';

defineProps<{
    task: Task;
    users: User[];
}>();

const isExpanded = ref(false);

// Formatting the date for the scan view

</script>

<template>
    <div
        class="group bg-white dark:bg-gray-900 border border-slate-200 dark:border-slate-800 rounded-xl shadow-sm transition-all hover:border-indigo-300 overflow-hidden"
    >
        <div
            @click="isExpanded = !isExpanded"
            class="p-4 flex items-center justify-between cursor-pointer select-none"
        >
            <div class="flex items-center gap-4 flex-1 min-w-0">
                <div
                    class="w-2 h-2 rounded-full shrink-0"
                    :class="statusDotClasses[task.status]"
                />

                <TooltipProvider v-if="task.assignee">
                    <Tooltip :delay-duration="200">
                        <TooltipTrigger as-child>
                            <div class="h-7 w-7 rounded-full bg-indigo-50 border-2 border-white flex items-center justify-center text-[9px] font-black text-indigo-600 shadow-sm cursor-help shrink-0">
                                {{ (task.assignee.first_name?.[0] || '') + (task.assignee.last_name?.[0] || '') }}
                            </div>
                        </TooltipTrigger>
                        <TooltipContent side="top" class="bg-slate-900 text-white text-[10px] font-bold px-3 py-1.5">
                            {{ task.assignee.first_name }} {{ task.assignee.last_name }}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                <TooltipProvider v-else>
                    <Tooltip :delay-duration="200">
                        <TooltipTrigger as-child>
                            <div class="h-7 w-7 rounded-full border-2 border-dashed border-slate-200 flex items-center justify-center bg-transparent shrink-0 cursor-help">
                                <User2 class="w-3 h-3 text-slate-400" />
                            </div>
                        </TooltipTrigger>
                        <TooltipContent side="top" class="bg-slate-900 text-white text-[10px] font-bold px-3 py-1.5">
                            Unassigned
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                <div class="flex flex-col min-w-0">
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-white truncate">
                        {{ task.title }}
                    </h4>
                    <div v-if="task.document" class="flex items-center gap-1 text-[10px] text-slate-400 font-medium">
                        <LinkIcon class="w-3 h-3" />
                        <span>{{ task.document.name }}</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4 text-right shrink-0">
                <div class="w-[120px] hidden md:flex justify-end">
                    <Badge variant="secondary"
                    class="uppercase text-[9px] tracking-tighter font-bold px-2 py-0"
                    :class="statusClasses[task.status]"
                    >
                        {{ STATUS_LABELS[task.status] || task.status }}
                    </Badge>
                </div>

                <div class="w-[100px] hidden md:flex justify-end">
                    <span
                        class="text-[10px] font-black uppercase tracking-tight"
                        :class="priorityClasses[task.priority]"
                    >
                        {{ PRIORITY_LABELS[task.priority] || task.priority }}
                    </span>
                </div>

                <div class="w-[100px] text-[11px] font-mono text-slate-500">
                    {{ formatDate(task.due_at) }}
                </div>

                <div class="ml-2 text-slate-300 group-hover:text-slate-600">
                    <ChevronDown v-if="!isExpanded" class="w-4 h-4" />
                    <ChevronUp v-else class="w-4 h-4" />
                </div>
            </div>
        </div>

        <div v-if="isExpanded" class="px-10 pb-4 border-t border-slate-50 bg-slate-50/50 dark:bg-slate-800/50">
            <div class="pt-4 space-y-4">
                <div class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed italic">
                    {{ task.description || 'No implementation notes provided.' }}
                </div>

                <div class="flex items-center justify-between">
                    <Link
                        :href="`/tasks/${task.id}`"
                        class="flex items-center gap-1.5 text-[11px] font-bold text-indigo-600 hover:text-indigo-700 uppercase tracking-wider"
                    >
                        <MessageSquare class="w-3.5 h-3.5" />
                        Open Discussion & Details
                    </Link>

                    <span class="text-[10px] text-slate-400">Created {{ new Date(task.created_at).toLocaleDateString() }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
