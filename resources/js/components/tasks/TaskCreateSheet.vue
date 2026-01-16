TypeScript

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import taskRoutes from '@/routes/tasks/index';
import { Sheet, SheetContent, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'vue-sonner';
import { FileText } from 'lucide-vue-next';

const props = defineProps<{
    open: boolean;
    projectId: string;
    documentId?: string | null;
    initialTitle?: string;
    initialDescription?: string;
    users?: User[];
}>();

const emit = defineEmits(['update:open', 'created']);

const form = useForm({
    project_id: props.projectId,
    document_id: props.documentId || null,
    title: props.initialTitle || '',
    description: '',
    status: 'todo',
    priority: 'medium',
    assignee_id: null as number | null,
    due_at: null as string | null,
});


// The missing function that was causing your TS-Plugin error
const handleAssigneeChange = (value: any) => {
    form.assignee_id = value === 'unassigned' ? null : parseInt(value);
};

const submit = () => {
    console.log('--- Form Submission ---');
    console.log('Payload being sent:', form.data());

    form.post(taskRoutes.store().url, {
        onSuccess: () => {
            toast.success('Task Created');
            emit('update:open', false);
            emit('created');
            form.reset();
        },
        onError: (err) => {
            console.error('Server Errors:', err);
        }
    });
};
</script>

<template>
    <Sheet :open="open" @update:open="val => emit('update:open', val)">
        <SheetContent class="sm:max-w-[900px] p-0 flex flex-col h-full">
            <div class="flex h-full divide-x divide-slate-100">

                <div class="w-1/2 flex flex-col bg-slate-50/50">
                    <div class="p-6 border-b bg-white">
                        <h3 class="font-bold text-slate-900 flex items-center gap-2">
                            <FileText class="w-4 h-4 text-indigo-500" />
                            Reference: {{ initialTitle }}
                        </h3>
                    </div>
                    <div class="p-6 overflow-y-auto prose prose-sm prose-slate max-w-none">
                        <div v-html="initialDescription"></div>
                    </div>
                </div>

                <div class="w-1/2 flex flex-col bg-white">
                    <div class="p-6 border-b">
                        <SheetTitle>Create Implementation Task</SheetTitle>
                        <SheetDescription>Set the execution details for this spec.</SheetDescription>
                    </div>

                    <div class="p-6 space-y-6 overflow-y-auto flex-1">
                        <div class="grid gap-2">
                            <Label>Task Title</Label>
                            <Input v-model="form.title" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label>Assignee</Label>
                                <Select
                                    :model-value="form.assignee_id?.toString() ?? 'unassigned'"
                                    @update:model-value="handleAssigneeChange"
                                >
                                    <SelectTrigger class="bg-white h-11 border-slate-200">
                                        <SelectValue placeholder="Assignee" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="unassigned">Unassigned</SelectItem>
                                        <SelectItem
                                            v-for="user in users"
                                            :key="user.id"
                                            :value="user.id.toString()"
                                        >
                                            {{ user.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label>Implementation Notes (Optional)</Label>
                            <textarea v-model="form.description" class="min-h-[100px] w-full rounded-md border p-3 text-sm"></textarea>
                        </div>
                    </div>

                    <div class="p-6 border-t flex justify-end gap-3 bg-slate-50/50">
                        <Button variant="outline" @click="emit('update:open', false)">Cancel</Button>
                        <Button @click="submit" :disabled="form.processing" class="bg-indigo-600">Create Task</Button>
                    </div>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
