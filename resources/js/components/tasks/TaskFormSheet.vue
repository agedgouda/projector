<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import taskRoutes from '@/routes/tasks/index';
import { Sheet, SheetContent, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { toast } from 'vue-sonner';
import { FileText, ClipboardList, Loader2 } from 'lucide-vue-next';
import TaskForm from './TaskForm.vue';

const props = defineProps<{
    open: boolean;
    projectId: string;
    documentId?: string | null;
    initialTitle?: string;
    initialDescription?: string;
    users?: User[];
    task?: Task;
}>();

const emit = defineEmits(['update:open', 'created']);

type TaskField = 'project_id' | 'document_id' | 'title' | 'description' | 'status' | 'priority' | 'assignee_id' | 'due_at';

// Tell Inertia that the form is a flat record of these Task fields.
const form = useForm<Record<TaskField, any>>({
    project_id: props.projectId,
    document_id: props.documentId || null,
    title: '',
    description: '',
    status: 'todo',
    priority: 'medium',
    assignee_id: null,
    due_at: null,
});

// Watch for changes to the task prop to toggle between Create and Edit
watch(() => props.task, (newTask) => {
    if (newTask) {
        // Edit Mode
        form.defaults({
            project_id: props.projectId,
            document_id: props.documentId || null,
            title: newTask.title,
            description: newTask.description || '',
            status: newTask.status,
            priority: newTask.priority || 'medium',
            assignee_id: newTask.assignee_id,
            due_at: newTask.due_at,
        });
    } else {
        // Create Mode
        form.defaults({
            project_id: props.projectId,
            document_id: props.documentId || null,
            title: props.initialTitle || '',
            description: '',
            status: 'todo',
            priority: 'medium',
            assignee_id: null,
            due_at: null,
        });
    }
    form.reset();
}, { immediate: true });

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(props.task ? 'Task Updated' : 'Task Created');
            emit('update:open', false);
            emit('created');
            if (!props.task) form.reset();
        },
    };

    if (props.task) {
        // Edit Mode: PUT request using Wayfinder route
        form.put(taskRoutes.update(props.task.id).url, options);
    } else {
        // Create Mode: POST request using Wayfinder route
        form.post(taskRoutes.store().url, options);
    }
};
</script>

<template>
    <Sheet :open="open" @update:open="val => emit('update:open', val)">
        <SheetContent class="sm:max-w-[700px] p-0 flex flex-col h-full bg-white">
            <div class="p-8 border-b shrink-0 bg-slate-50/50">
                <div class="flex items-center gap-3 mb-1">
                    <div class="p-2 bg-indigo-600 rounded-lg text-white">
                        <ClipboardList class="w-5 h-5" />
                    </div>
                    <SheetTitle class="text-2xl font-black text-slate-900 tracking-tight">
                        {{ task ? 'Edit Task' : 'New Implementation Task' }}
                    </SheetTitle>
                </div>
                <SheetDescription class="text-slate-500 ml-11">
                    {{ task ? 'Update the technical details for this task.' : 'Convert this specification into an actionable work item.' }}
                </SheetDescription>
            </div>

            <div class="flex-1 overflow-y-auto p-8 space-y-8 custom-scrollbar">

                <TaskForm :form="form" :users="users || []" />

                <div class="space-y-4">
                    <div class="relative pt-4">
                        <div class="absolute inset-0 flex items-center" aria-hidden="true">
                            <div class="w-full border-t border-slate-100"></div>
                        </div>
                        <div class="relative flex justify-start">
                            <span class="bg-white pr-3 text-[10px] font-black uppercase tracking-[0.2em] text-slate-300">Source Specification</span>
                        </div>
                    </div>

                    <div class="bg-indigo-50/30 rounded-2xl p-6 border border-indigo-100/50">
                        <h4 class="font-bold text-indigo-900 text-sm mb-3 flex items-center gap-2">
                            <FileText class="w-4 h-4 text-indigo-400" />
                            {{ initialTitle }}
                        </h4>
                        <div
                            class="prose prose-sm prose-slate max-w-none text-slate-600 line-clamp-6"
                            v-html="initialDescription"
                        ></div>
                    </div>
                </div>
            </div>

            <div class="p-8 border-t flex justify-end gap-3 bg-slate-50/50 shrink-0">
                <Button variant="ghost" @click="emit('update:open', false)" class="rounded-xl px-6 text-slate-500 font-bold">
                    Cancel
                </Button>
                <Button
                    @click="submit"
                    :disabled="form.processing"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-10 font-black shadow-lg shadow-indigo-200 transition-all h-12 min-w-[160px]"
                >
                    <Loader2 v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
                    {{ task ? 'Update Task' : 'Create Task' }}
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
