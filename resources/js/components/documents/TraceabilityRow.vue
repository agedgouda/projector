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
            class="flex items-center transition-all group relative"
            :class="[level === 0 ? 'mb-3' : 'mb-1 opacity-90']"
        >
            <div v-if="isSelected" class="absolute left-0 top-2 bottom-2 w-1 bg-indigo-600 rounded-full z-10"></div>

            <div class="flex-1 flex items-center relative">

                <div
                    class="flex-1 flex items-center bg-white dark:bg-gray-900 border border-slate-200 dark:border-slate-800 py-2.5 px-4 rounded-xl shadow-sm transition-all hover:border-indigo-300 min-w-0"
                    :class="[
                        isSelected ? 'border-indigo-400 ring-1 ring-indigo-100' : 'border-slate-200 dark:border-slate-800 hover:border-slate-300',
                        level === 0 ? '' : (level === 1 ? 'ml-8' : 'ml-16')
                    ]"
                    @click="handleOpenSheet"
                >
                    <div
                        v-if="item.children?.length"
                        class="w-6 flex items-center justify-center cursor-pointer hover:bg-slate-100 rounded-md h-6 mr-2 transition-colors shrink-0"
                        @click.stop="emit('toggleRoot', item.id)"
                    >
                        <ChevronRight
                            :class="['text-slate-400 transition-transform duration-300', { 'rotate-90': isTreeExpanded }]"
                            class="h-4 w-4"
                        />
                    </div>

                    <div class="h-7 w-7 rounded-lg flex items-center justify-center shrink-0 mr-4" :class="isSelected ? 'bg-indigo-600 text-white' : 'bg-slate-50 dark:bg-slate-800 text-slate-500'">
                        <FileText class="h-4 w-4" />
                    </div>

                    <div class="flex-1 flex items-center gap-3 overflow-hidden mr-4">
                        <div class="font-bold truncate text-sm tracking-tight text-slate-900 dark:text-slate-100">
                            {{ item.name }}
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-[9px] font-black tracking-widest uppercase text-slate-400">
                                {{ getDocLabel(item.type) }}
                            </span>
                            <div v-if="item.tasks?.length" class="flex items-center gap-1 bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 text-[8px] px-1.5 py-0.5 rounded-md font-black">
                                <CheckSquare class="w-2.5 h-2.5" /> {{ item.tasks.length }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 text-right shrink-0">
                        <div class="w-[120px] hidden md:block"></div>
                        <Button
                            variant="ghost" size="sm" @click.stop="emit('handleReprocess', item.id)"
                            class="h-8 px-3 bg-violet-50 dark:bg-violet-950/30 text-violet-700 dark:text-violet-400 border border-violet-100 dark:border-violet-900/50 rounded-xl relative overflow-hidden group/ai"
                        >
                            <Sparkles class="h-3.5 w-3.5 mr-2" />
                            <span class="text-[10px] font-black uppercase tracking-wider">Reprocess</span>
                        </Button>

                        <Button
                            variant="ghost" size="sm" @click.stop="handleOpenSheet"
                            class="h-8 px-3 bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200/60 dark:border-slate-700 rounded-xl group/view"
                        >
                            <Eye class="h-3.5 w-3.5 mr-2" />
                            <span class="text-[10px] font-black uppercase tracking-wider">Details</span>
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="isTreeExpanded && item.children?.length" class="w-full">
            <TraceabilityRow
                v-for="child in item.children"
                :key="'doc-' + child.id"
                :item="child"
                :level="level + 1"
                :expanded-root-ids="expandedRootIds"
                :get-doc-label="getDocLabel"
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
