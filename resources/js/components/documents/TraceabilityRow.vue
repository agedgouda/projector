<script setup lang="ts">
import { computed } from 'vue';
import { ChevronRight, FileText, CheckSquare, Eye, Sparkles } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    item: any;
    level: number;
    expandedRootIds: Set<string | number>;
    getDocLabel: (type: string) => string;
    selectedSheetId: string | number | null;
}>();

const emit = defineEmits([
    'toggleRoot', 'handleReprocess', 'onDeleteRequested',
    'prepareEdit', 'submit', 'openSheet'
]);

const isTreeExpanded = computed(() => props.expandedRootIds instanceof Set && props.expandedRootIds.has(props.item.id));
const isSelected = computed(() => props.selectedSheetId === props.item.id);

const handleOpenSheet = () => {
    emit('openSheet', props.item); // Pass the whole item for convenience
};
</script>

<template>
    <div class="flex flex-col">
        <div
            class="flex items-center px-10 transition-all border-b border-slate-50 group relative"
            :class="[level === 0 ? 'py-4' : 'py-2.5', isSelected ? 'bg-indigo-50/40' : 'hover:bg-slate-50/50']"
        >
            <div v-if="isSelected" class="absolute left-0 top-0 bottom-0 w-1 bg-indigo-600 shadow-[2px_0_8px_rgba(79,70,229,0.3)]"></div>

            <div class="flex-1 flex items-center gap-3 relative" :class="[level === 0 ? '' : (level === 1 ? 'pl-12' : 'pl-24')]">
                <template v-if="level > 0">
                    <div class="absolute left-6 top-0 bottom-0 w-px bg-slate-200"></div>
                    <div v-if="level > 1" class="absolute left-[24px] top-0 bottom-0 w-px bg-slate-200"></div>
                    <div class="absolute top-1/2 h-px bg-slate-200" :class="[level === 1 ? 'left-6 w-5' : 'left-[24px] w-4']"></div>
                </template>

                <div class="w-6 flex items-center justify-center cursor-pointer hover:bg-slate-200 rounded-md h-6" @click="emit('toggleRoot', item.id)">
                    <ChevronRight v-if="item.children?.length" :class="['text-slate-400 transition-transform duration-300', { 'rotate-90': isTreeExpanded }]" class="h-4 w-4" />
                </div>

                <div
                    class="flex-1 flex items-center bg-white border py-2 px-3 pr-2 rounded-xl shadow-sm cursor-pointer transition-all min-w-0"
                    :class="isSelected ? 'border-indigo-400 ring-1 ring-indigo-100' : 'border-slate-200 hover:border-indigo-300'"
                    @click="handleOpenSheet"
                >
                    <div class="p-1.5 rounded-lg mr-3 shrink-0" :class="isSelected ? 'bg-indigo-600 text-white' : 'bg-blue-50 text-blue-600'">
                        <FileText class="h-3.5 w-3.5" />
                    </div>

                    <div class="flex-1 overflow-hidden mr-4">
                        <div class="font-bold truncate text-[10px] uppercase tracking-tight" :class="isSelected ? 'text-indigo-900' : 'text-slate-900'">
                            {{ item.name }}
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[8px] font-bold tracking-widest uppercase" :class="isSelected ? 'text-indigo-400' : 'text-slate-400'">
                                {{ getDocLabel(item.type) }}
                            </span>
                            <div v-if="item.tasks?.length" class="flex items-center gap-1 bg-emerald-50 text-emerald-600 text-[8px] px-1 py-0.5 rounded-md font-black">
                                <CheckSquare class="w-2.5 h-2.5" /> {{ item.tasks.length }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5 shrink-0">
                        <Button
                            variant="ghost" size="sm" @click.stop="emit('handleReprocess', item.id)"
                            class="h-7 px-2.5 bg-violet-50 text-violet-700 border border-violet-100 rounded-lg relative overflow-hidden group/ai"
                        >
                            <span class="absolute inset-0 bg-violet-400/10 animate-ai-pulse pointer-events-none"></span>
                            <Sparkles class="h-3 w-3 mr-1.5 group-hover/ai:scale-110 transition-transform" />
                            <span class="text-[9px] font-black uppercase tracking-wider">Reprocess</span>
                        </Button>

                        <Button
                            variant="ghost" size="sm" @click.stop="handleOpenSheet"
                            class="h-7 px-2.5 bg-slate-50 text-slate-600 border border-slate-200/60 rounded-lg group/view transition-all"
                        >
                            <Eye class="h-3 w-3 mr-1.5 group-hover/view:text-slate-900 transition-colors" />
                            <span class="text-[9px] font-black uppercase tracking-wider text-slate-500 group-hover/view:text-slate-700">Details</span>
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="isTreeExpanded && item.children?.length" class="relative">
            <TraceabilityRow
                v-for="child in item.children" :key="'doc-' + child.id" :item="child" :level="level + 1"
                :expanded-root-ids="expandedRootIds" :get-doc-label="getDocLabel"
                :selected-sheet-id="selectedSheetId"
                @toggle-root="id => emit('toggleRoot', id)"
                @handle-reprocess="id => emit('handleReprocess', id)"
                @on-delete-requested="i => emit('onDeleteRequested', i)"
                @prepare-edit="i => emit('prepareEdit', i)"
                @submit="cb => emit('submit', cb)"
                @open-sheet="i => emit('openSheet', i)"
            />
        </div>
    </div>
</template>
