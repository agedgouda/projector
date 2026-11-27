<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    ChevronDown,
    ChevronRight,
    Clock,
    FileText,
    Pencil,
    RefreshCw,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    doc: any; // Using any here to match your ProjectDocument type
    isExpanded: boolean;
}>();

const emit = defineEmits<{
    (e: 'toggle', id: string | number): void;
    (e: 'edit', doc: any): void;
    (e: 'delete', doc: any): void;
    (e: 'reprocessing', id: string | number): void;
}>();

const onToggle = () => emit('toggle', props.doc.id);
const onEdit = () => emit('edit', props.doc);
const onDelete = () => emit('delete', props.doc);

// The "For Real" trigger: Just screams the ID to the parent
const onReprocess = () => emit('reprocessing', props.doc.id);

const isProcessed = computed(() => props.doc.processed_at !== null);
</script>

<template>
    <div
        class="group relative flex flex-col rounded-xl border border-transparent transition-all hover:border-slate-200 hover:bg-slate-50/50 dark:hover:border-slate-700 dark:hover:bg-slate-800/50"
        :class="{ 'border-slate-200 bg-slate-50 shadow-sm': isExpanded }"
    >
        <div class="flex items-center justify-between p-3">
            <div class="flex flex-1 items-center gap-3 overflow-hidden">
                <button
                    @click="onToggle"
                    type="button"
                    class="flex h-6 w-6 items-center justify-center rounded-md hover:bg-slate-200 dark:hover:bg-slate-700"
                >
                    <component
                        :is="isExpanded ? ChevronDown : ChevronRight"
                        class="h-4 w-4 text-slate-500"
                    />
                </button>
                <FileText class="h-4 w-4 shrink-0 text-projector-primary-500" />
                <div class="flex flex-col overflow-hidden">
                    <span
                        class="truncate text-sm font-black text-slate-900 dark:text-slate-100"
                        >{{ doc.name }}</span
                    >
                    <div class="flex items-center gap-2">
                        <span
                            class="text-[10px] font-black tracking-widest text-slate-500 uppercase dark:text-slate-400"
                            >{{ doc.type.replace('_', ' ') }}</span
                        >

                        <div
                            v-if="!isProcessed"
                            class="flex items-center gap-1"
                        >
                            <Clock
                                class="h-3 w-3 animate-pulse text-amber-500"
                            />
                            <span
                                class="text-[10px] font-bold text-amber-600 uppercase"
                                >Processing</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100"
            >
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8 text-slate-400 hover:text-projector-primary-600"
                                :disabled="!isProcessed"
                                @click.stop="onReprocess"
                            >
                                <RefreshCw
                                    :class="[
                                        'h-3.5 w-3.5',
                                        {
                                            'animate-spin text-projector-primary-600':
                                                !isProcessed,
                                        },
                                    ]"
                                />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>{{
                            isProcessed
                                ? 'Reprocess with AI'
                                : 'AI Engine Working...'
                        }}</TooltipContent>
                    </Tooltip>

                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-slate-400 hover:text-slate-900"
                        @click.stop="onEdit"
                    >
                        <Pencil class="h-3.5 w-3.5" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-slate-400 hover:text-red-600"
                        @click.stop="onDelete"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                    </Button>
                </TooltipProvider>
            </div>
        </div>

        <div v-if="isExpanded" class="px-12 pb-4">
            <div
                class="max-h-40 overflow-y-auto rounded-md border border-slate-200 bg-white p-3 text-xs whitespace-pre-wrap text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
            >
                {{ doc.content || 'No content preview available.' }}
            </div>
        </div>
    </div>
</template>
