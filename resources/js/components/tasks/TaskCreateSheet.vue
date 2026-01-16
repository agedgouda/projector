<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import taskRoutes from '@/routes/tasks/index'; // Wayfinder integration
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'vue-sonner';
import { RefreshCw } from 'lucide-vue-next';

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
    description: props.initialDescription || '',
    status: 'todo',
    priority: 'medium',
    assignee_id: null as number | null,
    due_at: null as string | null,
});

const submit = () => {
    // Access the .url property of the resolved RouteDefinition
    form.post(taskRoutes.store().url, {
        onSuccess: () => {
            toast.success('Task Created', {
                description: `"${form.title}" has been added to the project.`
            });
            emit('update:open', false);
            emit('created');
            form.reset();
        },
    });
};

</script>

<template>
    <Sheet :open="open" @update:open="val => emit('update:open', val)">
        <SheetContent class="sm:max-w-[500px] overflow-y-auto">
            <SheetHeader>
                <SheetTitle>Create Task</SheetTitle>
                <SheetDescription>
                    Convert this document into an actionable project task.
                </SheetDescription>
            </SheetHeader>

            <div class="grid gap-6 py-6">
                <div class="grid gap-2">
                    <Label :class="{ 'text-destructive': form.errors.title }">Task Title</Label>
                    <Input v-model="form.title" />
                    <p v-if="form.errors.title" class="text-xs text-destructive">{{ form.errors.title }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Assignee</Label>
                        <Select
                            :model-value="form.assignee_id?.toString() ?? 'unassigned'"
                            @update:model-value="(v: any) => form.assignee_id = v === 'unassigned' ? null : parseInt(v)"
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select an assignee" />
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
                    <div class="grid gap-2">
                        <Label>Priority</Label>
                        <Select v-model="form.priority">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="low">Low</SelectItem>
                                <SelectItem value="medium">Medium</SelectItem>
                                <SelectItem value="high">High</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Description</Label>
                    <textarea
                        v-model="form.description"
                        class="min-h-[150px] w-full rounded-md border border-input p-3 text-sm focus-visible:ring-2 focus-visible:ring-indigo-500 outline-none"
                    ></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t pt-6">
                <Button variant="outline" @click="emit('update:open', false)">Cancel</Button>
                <Button @click="submit" :disabled="form.processing" class="bg-indigo-600">
                    <RefreshCw v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
                    Create Task
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
