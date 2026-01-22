<script setup lang="ts">
import { computed, watch } from 'vue';
import type { InertiaForm } from '@inertiajs/vue3';
import { RefreshCw, Bold, Italic, List, ListOrdered } from 'lucide-vue-next';
import { EditorContent } from '@tiptap/vue-3';
import { useDocumentEditor } from '@/composables/useDocumentEditor';

// UI Components
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter
} from '@/components/ui/dialog';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue
} from '@/components/ui/select';

const props = defineProps<{
    open: boolean;
    mode: 'create' | 'edit';
    form: InertiaForm<Partial<Omit<DocumentFields, 'id'>> & { id?: string | number | null }>;
    requirementStatus?: RequirementStatus[];
    users?: User[];
}>();

const emit = defineEmits(['update:open', 'submit']);

// Use the new composable
const { editor } = useDocumentEditor(
    props.form.content,
    (html) => updateField('content', html)
);

// 1. Proxy the Dialog state
const isOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value)
});

// 2. Local methods to update fields
const updateField = <K extends keyof ProjectDocument>(field: K, value: any) => {
    (props.form as any)[field] = value;
};

const handleAssigneeChange = (value: any) => {
    if (value === 'unassigned') {
        updateField('assignee_id', null);
    } else {
        updateField('assignee_id', parseInt(value) as any);
    }
};

watch(() => props.open, (isNowOpen) => {
    if (isNowOpen && editor.value) {
        // Update the editor with the current form content
        // This ensures if the form was reset, the editor reflects it
        editor.value.commands.setContent(props.form.content || '');
    }
});

watch(() => props.open, (isOpen) => {
    if (isOpen && editor.value) {
        // This clears the "stuck" text when you reopen the modal
        editor.value.commands.setContent(props.form.content || '');
    }
});
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-[90vw] max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ mode === 'create' ? 'Add' : 'Edit' }} Document</DialogTitle>
                <DialogDescription>
                    {{ mode === 'create' ? 'Create a new document.' : 'Update document details.' }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-6 py-4">
                <div class="grid gap-2">
                    <Label :class="{ 'text-destructive': form.errors && !!form.errors.name }">Document Name</Label>
                    <Input
                        :model-value="form.name ?? ''"
                        @update:model-value="(v) => updateField('name', v)"
                        :class="{ 'border-destructive': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                </div>

                <div class="grid gap-2">
                    <Label :class="{ 'text-destructive': form.errors.type }">Category</Label>
                    <Select
                        v-if="requirementStatus && requirementStatus.length > 0"
                        :model-value="form.type ?? undefined"
                        @update:model-value="(v) => updateField('type', v)"
                    >
                        <SelectTrigger :class="{ 'border-destructive': form.errors.type }">
                            <SelectValue :placeholder="form.type || 'Select a category'" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="req in requirementStatus" :key="req.key" :value="req.key">
                                {{ req.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <div v-else class="flex h-10 w-full items-center justify-between rounded-md border border-input bg-slate-50 px-3 py-2 text-sm text-muted-foreground opacity-70 cursor-not-allowed">
                        {{ form.type || 'No category assigned' }}
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label :class="{ 'text-destructive': form.errors.content }">Content</Label>
                    <div class="border border-slate-200 rounded-md overflow-hidden focus-within:ring-2 focus-within:ring-indigo-500 transition-all">
                        <div v-if="editor" class="flex items-center gap-1 p-2 border-b border-slate-100 bg-slate-50/50">
                            <Button variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleBold().run()" :class="{ 'bg-slate-200': editor.isActive('bold') }">
                                <Bold class="h-4 w-4" />
                            </Button>
                            <Button variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleItalic().run()" :class="{ 'bg-slate-200': editor.isActive('italic') }">
                                <Italic class="h-4 w-4" />
                            </Button>
                            <div class="w-px h-4 bg-slate-200 mx-1"></div>
                            <Button variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleBulletList().run()" :class="{ 'bg-slate-200': editor.isActive('bulletList') }">
                                <List class="h-4 w-4" />
                            </Button>
                            <Button variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleOrderedList().run()" :class="{ 'bg-slate-200': editor.isActive('orderedList') }">
                                <ListOrdered class="h-4 w-4" />
                            </Button>
                        </div>
                        <editor-content :editor="editor" class="min-h-[200px]" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label :class="{ 'text-destructive': form.errors.assignee_id }">Assignee</Label>
                    <Select
                        :model-value="form.assignee_id?.toString() ?? 'unassigned'"
                        @update:model-value="handleAssigneeChange"
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select a user to assign" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="unassigned">Unassigned</SelectItem>
                            <SelectItem v-for="user in users" :key="user.id" :value="user.id.toString()">
                                {{ user.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <DialogFooter class="mt-4 shrink-0">
                <Button variant="outline" @click="isOpen = false">Cancel</Button>
                <Button @click="emit('submit')" :disabled="form.processing" class="bg-indigo-600">
                    <RefreshCw v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
                    {{ mode === 'create' ? 'Save' : 'Update' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style>
.prose ul { list-style-type: disc; padding-left: 1.5rem; }
.prose ol { list-style-type: decimal; padding-left: 1.5rem; }
</style>
