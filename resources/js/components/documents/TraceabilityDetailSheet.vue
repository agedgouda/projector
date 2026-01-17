<script setup lang="ts">
import { ref, watch } from 'vue';
import {
    Trash2, Plus, FileType, Edit2
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import {
    Tooltip, TooltipContent, TooltipProvider, TooltipTrigger
} from '@/components/ui/tooltip';
import { toast } from 'vue-sonner';
import TaskCreateSheet from '@/components/tasks/TaskFormSheet.vue';
import InlineDocumentForm from './InlineDocumentForm.vue';
import { formatDate } from '@/lib/utils'

const props = defineProps<{
    open: boolean;
    item: any;
    getDocLabel: (type: string) => string;
    requirementStatus?: any[];
    users?: any[];
    form?: any;
    activeEditingId: string | number | null;
}>();

const emit = defineEmits(['update:open', 'handleReprocess', 'onDeleteRequested', 'prepareEdit', 'submit','task-created']);

const localDisplayContent = ref(props.item?.content);
watch(() => props.item?.content, (newVal) => { localDisplayContent.value = newVal; });

const isTaskSheetOpen = ref(false);
const selectedTask = ref<any>(null); // Track the task being edited

const openCreateTask = () => {
    selectedTask.value = null; // Reset for create mode
    isTaskSheetOpen.value = true;
};

const openEditTask = (task: any) => {
    selectedTask.value = task; // Set the task for edit mode
    isTaskSheetOpen.value = true;
};

const handleFormSubmit = () => {
    localDisplayContent.value = props.form.content;
    emit('submit', () => {
        emit('prepareEdit', { id: null });
        toast.success('Changes saved');
    });
};

const STATUS_LABELS: Record<string, string> = {
    todo: 'To Do',
    backlog: 'Backlog',
    in_progress: 'In Progress',
    done: 'Done'
};


</script>

<template>
    <Sheet :open="open" @update:open="val => emit('update:open', val)">
        <SheetContent side="right" class="sm:max-w-[600px] p-0 flex flex-col shadow-2xl border-l border-slate-200">
            <template v-if="item">
                <div class="px-8 py-6 border-b bg-white flex items-center justify-between sticky top-0 z-10">
                    <div class="space-y-1">
                        <div class="text-[9px] font-black uppercase tracking-[0.2em] text-indigo-600 flex items-center gap-2">
                            <FileType class="h-3 w-3" /> {{ getDocLabel(item.type) }}
                        </div>
                        <SheetTitle class="text-lg font-bold text-slate-900 leading-tight">{{ item.name }}</SheetTitle>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button variant="outline" size="sm" @click="emit('prepareEdit', item)" class="h-8 text-[10px] font-black uppercase tracking-widest px-4 border-slate-200">
                            <Edit2 class="h-3 w-3 mr-2" /> Edit
                        </Button>
                        <Button variant="ghost" size="icon" @click.stop="emit('onDeleteRequested', item)" class="h-9 w-9 text-slate-400 hover:text-red-600 hover:bg-red-50">
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto p-8 custom-scrollbar">
                    <div v-if="activeEditingId === item.id" class="mb-10">
                        <InlineDocumentForm
                            mode="edit" :form="form" :requirement-status="requirementStatus"
                            :users="users" @submit="handleFormSubmit" @cancel="emit('prepareEdit', { id: null })"
                        />
                    </div>

                    <div class="space-y-12">
                        <section>
                            <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-6 flex items-center gap-2">
                                <div class="w-4 h-px bg-slate-200"></div> Description
                            </h3>
                            <div class="text-[13px] text-slate-600 leading-relaxed prose prose-slate max-w-none" v-html="localDisplayContent || 'No description provided.'"></div>
                        </section>

                        <section>
                            <div class="flex items-center justify-between mb-6">
                                <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 flex items-center gap-2">
                                    <div class="w-4 h-px bg-slate-200"></div> Implementation Tasks
                                </h3>
                                <Button @click="openCreateTask" variant="ghost" size="sm" class="h-7 text-[9px] font-black uppercase tracking-widest text-indigo-600 hover:bg-indigo-50">
                                    <Plus class="h-3 w-3 mr-1" /> Add Task
                                </Button>
                            </div>

                            <div v-if="item.tasks?.length" class="space-y-3">
                                <div v-for="task in item.tasks" :key="task.id" class="group flex items-center justify-between bg-white p-4 rounded-2xl border border-slate-200 shadow-sm hover:border-indigo-100 transition-colors">
                                    <div class="flex items-center gap-4">
                                        <div class="h-2 w-2 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]"></div>
                                        <span class="text-[12px] font-bold text-slate-700 tracking-tight">{{ task.title }}</span>
                                    </div>
                                    <div class="flex items-center gap-4 border-l pl-4 border-slate-100">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            @click="openEditTask(task)"
                                            class="h-7 w-7 text-slate-400 hover:text-indigo-600 opacity-0 group-hover:opacity-100 transition-all"
                                        >
                                            <Edit2 class="h-3.5 w-3.5" />
                                        </Button>

                                        <span
                                            class="text-[9px] font-black uppercase px-2 py-0.5 rounded-full tracking-tighter"
                                            :class="{
                                                'bg-slate-100 text-slate-500': task.status === 'backlog' || task.status === 'todo',
                                                'bg-amber-100 text-amber-700': task.status === 'in_progress',
                                                'bg-emerald-100 text-emerald-700': task.status === 'done',
                                            }"
                                        >
                                            {{ STATUS_LABELS[task.status] }}
                                        </span>
                                        <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-full tracking-tighter">
                                            Due: {{ formatDate(task.due_at) }}
                                        </span>
                                        <TooltipProvider v-if="task.assignee">
                                            <Tooltip :delay-duration="200">
                                                <TooltipTrigger as-child>
                                                    <div class="h-7 w-7 rounded-full bg-indigo-50 border-2 border-white flex items-center justify-center text-[9px] font-black text-indigo-600 shadow-sm cursor-help transition-all">
                                                        {{ (task.assignee.first_name?.[0] || '') + (task.assignee.last_name?.[0] || '') }}
                                                    </div>
                                                </TooltipTrigger>
                                                <TooltipContent side="top" class="bg-slate-900 text-white border-none text-[10px] font-bold px-3 py-1.5">
                                                    {{ task.assignee.first_name }} {{ task.assignee.last_name }}
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <TaskCreateSheet
                    v-model:open="isTaskSheetOpen"
                    :task="selectedTask"
                    :project-id="item.project_id"
                    :document-id="item.id"
                    :initial-title="item.name"
                    :initial-description="item.content"
                    :users="users"
                    @created="$emit('task-created')"
                />
            </template>
        </SheetContent>
    </Sheet>
</template>
