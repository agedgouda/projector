<script setup lang="ts">
import { ChevronRightIcon, ChevronDownIcon, TrashIcon } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

defineProps<{
    doc: any;
    isExpanded: boolean;
}>();

const emit = defineEmits(['toggle', 'delete']);
</script>

<template>
    <li class="flex flex-col border border-slate-200 rounded-lg overflow-hidden bg-white mb-2 shadow-sm">
        <div
            @click="emit('toggle')"
            class="flex items-center justify-between p-3 cursor-pointer hover:bg-slate-50 transition-colors group"
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
                    <span class="text-[10px] text-slate-400 uppercase tracking-tight">
                        Added {{ new Date(doc.created_at).toLocaleDateString() }}
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Button
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8 text-slate-400 hover:text-red-600 hover:bg-red-50"
                    @click.stop="emit('delete')"
                >
                    <TrashIcon class="w-4 h-4" />
                </Button>
            </div>
        </div>

        <div
            v-if="isExpanded"
            class="border-t border-slate-100 bg-slate-50/50 p-4 animate-in fade-in slide-in-from-top-1 duration-200"
        >
            <div class="prose prose-sm max-w-none">
                <p class="text-slate-600 whitespace-pre-wrap leading-relaxed text-sm italic border-l-2 border-slate-200 pl-4">
                    {{ doc.content }}
                </p>
            </div>
        </div>
    </li>
</template>
