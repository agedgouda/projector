<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    kanbanClasses,
    kanbanDotClasses,
    PRIORITY_LABELS,
    priorityClasses,
} from '@/lib/constants';
import { FLAT_ROW_HOVER, FLAT_ROW_SELECTED } from '@/lib/flat-ui';
import { ChevronDown, ChevronUp, MessageSquare, User2 } from '@lucide/vue';
import { ref } from 'vue';

import { formatDateMdy, formatTimestampMdy } from '@/lib/utils';

import { useDocumentActions } from '@/composables/useDocumentActions';

const props = defineProps<{
    task: ProjectDocument;
    users: User[];
}>();

const isExpanded = ref(false);

const { navigateToDetails } = useDocumentActions({
    project: { id: props.task.project_id } as any,
});
</script>

<template>
    <div
        class="group rounded-md transition-colors"
        :class="isExpanded ? FLAT_ROW_SELECTED : FLAT_ROW_HOVER"
    >
        <div
            @click="isExpanded = !isExpanded"
            class="flex h-12 cursor-pointer items-center justify-between gap-4 px-2 select-none"
        >
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <div
                    class="h-1.5 w-1.5 shrink-0 rounded-full"
                    :class="kanbanDotClasses.slate"
                />

                <TooltipProvider v-if="task.assignee">
                    <Tooltip :delay-duration="200">
                        <TooltipTrigger as-child>
                            <div
                                class="flex h-6 w-6 shrink-0 cursor-help items-center justify-center rounded-full border border-white bg-projector-primary-50 text-[9px] font-black text-projector-primary-600 dark:border-slate-900"
                            >
                                {{
                                    (task.assignee.first_name?.[0] || '') +
                                    (task.assignee.last_name?.[0] || '')
                                }}
                            </div>
                        </TooltipTrigger>
                        <TooltipContent
                            side="top"
                            class="bg-slate-900 px-3 py-1.5 text-[10px] font-bold text-white"
                        >
                            {{ task.assignee.first_name }}
                            {{ task.assignee.last_name }}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                <TooltipProvider v-else>
                    <Tooltip :delay-duration="200">
                        <TooltipTrigger as-child>
                            <div
                                class="flex h-6 w-6 shrink-0 cursor-help items-center justify-center rounded-full border border-dashed border-slate-200 dark:border-slate-700"
                            >
                                <User2 class="h-3 w-3 text-slate-400" />
                            </div>
                        </TooltipTrigger>
                        <TooltipContent
                            side="top"
                            class="bg-slate-900 px-3 py-1.5 text-[10px] font-bold text-white"
                        >
                            Unassigned
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                <h4
                    class="truncate text-[13px] font-semibold text-slate-900 dark:text-slate-100"
                >
                    {{ task.name }}
                </h4>
            </div>

            <div class="flex shrink-0 items-center gap-4 text-right">
                <div class="hidden w-[120px] justify-end md:flex">
                    <Badge
                        variant="secondary"
                        class="px-2 py-0 text-[9px] font-bold tracking-tighter uppercase"
                        :class="kanbanClasses.slate"
                    >
                        {{ task.task_status }}
                    </Badge>
                </div>

                <div class="hidden w-[100px] justify-end md:flex">
                    <span
                        class="text-[10px] font-black tracking-tight uppercase"
                        :class="priorityClasses[task.priority]"
                    >
                        {{ PRIORITY_LABELS[task.priority] || task.priority }}
                    </span>
                </div>

                <div class="w-[100px] font-mono text-[11px] text-slate-400">
                    {{ formatDateMdy(task.due_at) }}
                </div>

                <div
                    class="text-slate-300 transition-colors group-hover:text-slate-500"
                >
                    <ChevronDown v-if="!isExpanded" class="h-4 w-4" />
                    <ChevronUp v-else class="h-4 w-4" />
                </div>
            </div>
        </div>

        <div v-if="isExpanded" class="px-10 pb-4">
            <div class="space-y-4 pt-2">
                <div v-html="task.content"></div>

                <div class="flex items-center justify-between">
                    <button
                        type="button"
                        @click.stop="
                            () => navigateToDetails(task.project_id, task.id)
                        "
                        class="flex cursor-pointer items-center gap-1.5 text-[11px] font-bold tracking-wider text-projector-primary-600 uppercase hover:text-projector-primary-700"
                    >
                        <MessageSquare class="h-3.5 w-3.5" />
                        Details
                    </button>

                    <span class="text-[10px] text-slate-400"
                        >Created {{ formatTimestampMdy(task.created_at) }}</span
                    >
                </div>
            </div>
        </div>
    </div>
</template>
