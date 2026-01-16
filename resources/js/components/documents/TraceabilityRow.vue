<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import {
    ChevronRight, Trash2, FileText,
    CheckSquare, X, Clock, Search, Plus, RotateCw, FileType
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { toast } from 'vue-sonner';
import TaskCreateSheet from '@/components/tasks/TaskCreateSheet.vue';
import InlineDocumentForm from './InlineDocumentForm.vue';

const props = defineProps<{
    item: any;
    level: number;
    expandedRootIds: Set<string | number>;
    getDocLabel: (type: string) => string;
    getLeadUser: (item: any) => any;
    requirementStatus?: any[];
    users?: any[];
    form?: any;
    activeEditingId: string | number | null;
}>();

const emit = defineEmits(['toggleRoot', 'handleReprocess', 'onDeleteRequested', 'prepareEdit', 'submit']);

const localDisplayContent = ref(props.item.content);
const localDisplayUpdateAt = ref(props.item.updated_at);

watch(() => props.item.content, (newVal) => { localDisplayContent.value = newVal; });
watch(() => props.item.updated_at, (newVal) => { localDisplayUpdateAt.value = newVal; });

const isPreviewOpen = ref(false);
const isEditing = computed(() => props.activeEditingId === props.item.id);
const isTreeExpanded = computed(() => props.expandedRootIds instanceof Set && props.expandedRootIds.has(props.item.id));

const handleMainAction = () => {
    if (isEditing.value) emit('prepareEdit', { id: null });
    else isPreviewOpen.value = !isPreviewOpen.value;
};

const handleEditClick = () => {
    if (!isEditing.value) {
        emit('prepareEdit', props.item);
        isPreviewOpen.value = true;
    } else {
        emit('prepareEdit', { id: null });
    }
};

const handleFormSubmit = () => {
    localDisplayContent.value = props.form.content;
    localDisplayUpdateAt.value = new Date().toISOString();
    emit('submit', () => {
        emit('prepareEdit', { id: null });
        isPreviewOpen.value = true;
        toast.success('Changes saved');
    });
};

const isTaskSheetOpen = ref(false);
</script>

<template>
    <div class="flex flex-col">
        <div
            class="grid grid-cols-12 items-center px-10 transition-colors border-b border-slate-50"
            :class="[
                level === 0 ? 'py-5 hover:bg-slate-50/50' : 'relative py-3',
                isEditing ? 'bg-indigo-50/30' : '',
                isPreviewOpen && !isEditing ? 'bg-blue-50/5' : ''
            ]"
        >
            <div class="col-span-8 flex items-center gap-3 relative" :class="[level === 0 ? '' : (level === 1 ? 'pl-12' : 'pl-24')]">
                <template v-if="level > 0">
                    <div class="absolute left-6 top-0 bottom-0 w-px bg-slate-200"></div>
                    <div v-if="level > 1" class="absolute left-[24px] top-0 bottom-0 w-px bg-slate-200"></div>
                    <div class="absolute top-1/2 h-px bg-slate-200" :class="[level === 1 ? 'left-6 w-5' : 'left-[24px] w-4']"></div>
                </template>

                <div class="w-6 flex items-center justify-center cursor-pointer hover:bg-slate-100 rounded-md transition-colors" @click="emit('toggleRoot', item.id)">
                    <ChevronRight v-if="item.children?.length" :class="['text-slate-400 transition-transform duration-300', { 'rotate-90': isTreeExpanded }]" class="h-4 w-4" />
                </div>

                <div class="flex-1 flex items-center bg-white border border-slate-200 p-3 rounded-2xl shadow-sm" :class="{ 'ring-2 ring-indigo-500 border-transparent': isEditing }">
                    <div class="p-2 rounded-xl mr-3 bg-blue-50 text-blue-600">
                        <FileText class="h-4 w-4" />
                    </div>
                    <div class="flex-1 overflow-hidden">
                        <div class="font-bold text-slate-900 truncate text-[11px] uppercase">{{ item.name }}</div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="text-[9px] text-slate-400 font-bold tracking-widest uppercase">{{ getDocLabel(item.type) }}</span>
                            <div v-if="item.tasks?.length" class="flex items-center gap-1 bg-emerald-50 text-emerald-600 text-[9px] px-1.5 py-0.5 rounded-full font-black uppercase">
                                <CheckSquare class="w-2.5 h-2.5" /> {{ item.tasks.length }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-span-2 flex justify-center">
                <TooltipProvider v-if="getLeadUser(item)">
                    <Tooltip>
                        <TooltipTrigger>
                            <div class="h-7 w-7 rounded-full border-2 border-white shadow-sm bg-slate-100 flex items-center justify-center overflow-hidden">
                                <img v-if="getLeadUser(item).avatar" :src="getLeadUser(item).avatar" class="object-cover h-full w-full" />
                                <div v-else class="text-indigo-600 font-bold text-[10px]">
                                    {{ (getLeadUser(item).first_name?.[0] || '') + (getLeadUser(item).last_name?.[0] || '') }}
                                </div>
                            </div>
                        </TooltipTrigger>
                        <TooltipContent><p class="text-xs font-bold">{{ getLeadUser(item).first_name }} {{ getLeadUser(item).last_name }}</p></TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>

            <div class="col-span-2 flex justify-center gap-1">
                <Button variant="ghost" size="icon" @click.stop="handleMainAction" :class="[isPreviewOpen ? 'text-blue-600 bg-blue-50' : 'text-slate-400']" class="h-7 w-7">
                    <X v-if="isEditing" class="h-3.5 w-3.5" />
                    <Search v-else class="h-3.5 w-3.5" />
                </Button>
                <template v-if="!isEditing">
                    <Button variant="ghost" size="icon" @click.stop="emit('handleReprocess', item.id)" class="text-slate-400 h-7 w-7 hover:text-blue-600">
                        <RotateCw class="h-3.5 w-3.5" />
                    </Button>
                    <Button variant="ghost" size="icon" @click.stop="emit('onDeleteRequested', item)" class="text-slate-400 h-7 w-7 hover:text-red-600">
                        <Trash2 class="h-3.5 w-3.5" />
                    </Button>
                </template>
            </div>
        </div>

        <transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
            <div v-if="isPreviewOpen || isEditing" class="relative px-10 pb-6 bg-slate-50/30">
                <div class="ml-auto w-[calc(100%-60px)] pt-4">
                    <div v-if="isEditing" class="mb-4">
                        <InlineDocumentForm mode="edit" :form="form" :requirement-status="requirementStatus" :users="users" @submit="handleFormSubmit" @cancel="emit('prepareEdit', { id: null })" />
                    </div>

                    <div v-show="!isEditing" class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-100">
                                <div class="flex gap-4">
                                    <div class="flex items-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest"><FileType class="h-3 w-3" /> {{ getDocLabel(item.type) }}</div>
                                    <div v-if="item.updated_at" class="flex items-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest"><Clock class="h-3 w-3" /> {{ new Date(item.updated_at).toLocaleDateString() }}</div>
                                </div>
                                <Button variant="outline" size="sm" @click="handleEditClick" class="h-7 text-[10px] font-black uppercase tracking-widest border-slate-200">Edit Document</Button>
                            </div>

                            <div class="text-sm text-slate-600 leading-relaxed prose prose-slate max-w-none mb-8" v-html="localDisplayContent || 'No content provided.'"></div>

                            <div class="bg-slate-50 -mx-6 -mb-6 p-6 border-t border-slate-100">
                                <div class="flex items-center justify-between mb-4">
                                    <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 flex items-center gap-2">
                                        <CheckSquare class="h-3 w-3" /> Implementation Tasks
                                    </h4>
                                    <Button @click="isTaskSheetOpen = true" variant="ghost" class="h-7 text-emerald-600 text-[10px] font-black uppercase tracking-widest hover:bg-emerald-50">
                                        <Plus class="h-3 w-3 mr-1" /> Add Task
                                    </Button>
                                </div>

                                <div v-if="item.tasks?.length" class="grid gap-2">
                                    <div v-for="task in item.tasks" :key="task.id" class="flex items-center justify-between bg-white p-3 rounded-xl border border-slate-200 shadow-sm">
                                        <div class="flex items-center gap-3">
                                            <div class="h-2 w-2 rounded-full bg-emerald-500"></div>
                                            <span class="text-xs font-bold text-slate-700">{{ task.title }}</span>
                                        </div>
                                        <div class="flex items-center gap-4">
                                            <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded bg-slate-100 text-slate-500 border border-slate-200">{{ task.status }}</span>
                                            <TooltipProvider v-if="task.assignee">
                                                <Tooltip>
                                                    <TooltipTrigger>
                                                        <div class="h-6 w-6 rounded-full bg-indigo-50 border border-indigo-100 flex items-center justify-center text-[8px] font-black text-indigo-600">
                                                            {{ (task.assignee.first_name?.[0] || '') + (task.assignee.last_name?.[0] || '') }}
                                                        </div>
                                                    </TooltipTrigger>
                                                    <TooltipContent>{{ task.assignee.first_name }} {{ task.assignee.last_name }}</TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="text-center py-4 border-2 border-dashed border-slate-200 rounded-xl">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">No active tasks.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <div v-if="isTreeExpanded && item.children?.length" class="relative">
            <TraceabilityRow
                v-for="child in item.children" :key="'doc-' + child.id" :item="child" :level="level + 1"
                :expanded-root-ids="expandedRootIds" :active-editing-id="activeEditingId" :get-doc-label="getDocLabel"
                :get-lead-user="getLeadUser" :requirement-status="requirementStatus" :users="users" :form="form"
                @toggle-root="(id) => emit('toggleRoot', id)" @handle-reprocess="(id) => emit('handleReprocess', id)"
                @on-delete-requested="(i) => emit('onDeleteRequested', i)" @prepare-edit="(i) => emit('prepareEdit', i)" @submit="(cb) => emit('submit', cb)"
            />
        </div>

        <TaskCreateSheet v-model:open="isTaskSheetOpen" :project-id="item.project_id" :document-id="item.id" :initial-title="item.name" :initial-description="item.content" :users="users" />
    </div>
</template>

<style>
.prose ul { list-style-type: disc; padding-left: 1.5rem; }
.prose ol { list-style-type: decimal; padding-left: 1.5rem; }
</style>
