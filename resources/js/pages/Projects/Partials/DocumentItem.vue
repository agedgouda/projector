<script setup lang="ts">
import {
    ChevronRightIcon,
    ChevronDownIcon,
    TrashIcon,
    PencilIcon,
    FileTextIcon
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

defineProps<{
    doc: any;
    isExpanded: boolean;
}>();

const emit = defineEmits<{
    (e: 'toggle'): void;
    (e: 'delete'): void;
    (e: 'edit', doc: any): void;
}>();
</script>

<template>
    <li class="flex flex-col border border-slate-200 rounded-lg overflow-hidden bg-white mb-2 shadow-sm transition-all hover:border-slate-300">
        <div
            @click="emit('toggle')"
            class="flex items-center justify-between p-3 cursor-pointer hover:bg-slate-50/80 transition-colors group"
        >
            <div class="flex items-center gap-3 overflow-hidden">
                <div class="text-slate-400 group-hover:text-indigo-600 transition-colors">
                    <ChevronRightIcon v-if="!isExpanded" class="w-4 h-4" />
                    <ChevronDownIcon v-else class="w-4 h-4 text-indigo-600" />
                </div>

                <div class="flex flex-col min-w-0">
                    <span :class="['text-sm font-semibold truncate transition-colors', isExpanded ? 'text-indigo-700' : 'text-slate-800']">
                        {{ doc.name }}
                    </span>
                    <span class="text-[10px] text-slate-400 uppercase tracking-tight font-medium">
                        Last Modified {{ new Date(doc.updated_at || doc.created_at).toLocaleDateString() }}
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-1">
                <div :class="['flex items-center gap-1 transition-opacity duration-200', isExpanded ? 'opacity-100' : 'opacity-0 group-hover:opacity-100']">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50"
                        title="Edit Document"
                        @click.stop="emit('edit', doc)"
                    >
                        <PencilIcon class="w-4 h-4" />
                    </Button>

                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-slate-400 hover:text-red-600 hover:bg-red-50"
                        title="Delete Document"
                        @click.stop="emit('delete')"
                    >
                        <TrashIcon class="w-4 h-4" />
                    </Button>
                </div>
            </div>
        </div>

        <div
            v-if="isExpanded"
            class="border-t border-slate-100 bg-slate-50/50 p-4 animate-in fade-in slide-in-from-top-1 duration-200"
        >
            <div class="flex items-start gap-3 mb-3">
                <FileTextIcon class="w-4 h-4 text-slate-400 mt-0.5" />
                <div class="prose prose-sm max-w-none flex-1">
                    <p class="text-slate-600 whitespace-pre-wrap leading-relaxed text-sm italic border-l-2 border-slate-200 pl-4">
                        {{ doc.content || 'No content provided for this document.' }}
                    </p>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <Button
                    variant="link"
                    size="sm"
                    class="text-[11px] font-bold text-indigo-600 h-auto p-0 hover:no-underline"
                    @click.stop="emit('edit', doc)"
                >
                    <PencilIcon class="w-3 h-3 mr-1.5" />
                    Update Document Content
                </Button>
            </div>
        </div>
    </li>
</template>
