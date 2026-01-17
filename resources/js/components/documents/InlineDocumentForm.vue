<script setup lang="ts">
import { computed } from 'vue';
import type { InertiaForm } from '@inertiajs/vue3';
import { RefreshCw, Bold, Italic, List, ListOrdered } from 'lucide-vue-next';
import { EditorContent } from '@tiptap/vue-3';
import { useDocumentEditor } from '@/composables/useDocumentEditor';

// UI Components
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue
} from '@/components/ui/select';

const props = defineProps<{
    mode: 'create' | 'edit';
    form: InertiaForm<FlatTask>;
    requirementStatus?: RequirementStatus[];
    users?: User[];
}>();

const emit = defineEmits(['cancel', 'submit']);

// Use the new composable
const { editor } = useDocumentEditor(
    props.form.content,
    (html) => updateField('content', html)
);

const titleLabel = computed(() => {
    if (props.mode === 'create') return 'Add Document';
    const currentType = props.requirementStatus?.find(r => r.key === props.form.type);
    return `Edit ${currentType?.label || 'Document'}`;
});

const updateField = <K extends keyof ProjectDocument>(field: K, value: any) => {
    (props.form as any)[field] = value;
};

const handleAssigneeChange = (value: any) => {
    updateField('assignee_id', value === 'unassigned' ? null : parseInt(value));
};
</script>

<template>
    <div class="bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden mt-4">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h3 class="text-sm font-bold text-slate-900">{{ titleLabel }}</h3>
            <p class="text-[11px] text-slate-500 font-medium uppercase tracking-tight">
                {{ mode === 'create' ? 'Create a new document.' : 'Update document details.' }}
            </p>
        </div>

        <div class="p-6 space-y-6">
            <div class="flex flex-col md:flex-row gap-6">
                <div class="flex-[3] grid gap-2">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Document Name</Label>
                    <Input
                        :model-value="form.name ?? ''"
                        @update:model-value="(v) => updateField('name', v)"
                        class="bg-white h-11 border-slate-200"
                    />
                </div>

                <div v-if="mode === 'create'" class="flex-1 min-w-[180px] grid gap-2">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Category</Label>
                    <Select :model-value="form.type" @update:model-value="(v) => updateField('type', v)">
                        <SelectTrigger class="bg-white h-11 border-slate-200">
                            <SelectValue placeholder="Select..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="req in requirementStatus" :key="req.key" :value="req.key">{{ req.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex-1 min-w-[180px] grid gap-2">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Assignee</Label>
                    <Select :model-value="form.assignee_id?.toString() ?? 'unassigned'" @update:model-value="handleAssigneeChange">
                        <SelectTrigger class="bg-white h-11 border-slate-200">
                            <SelectValue placeholder="Assignee" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="unassigned">Unassigned</SelectItem>
                            <SelectItem v-for="user in users" :key="user.id" :value="user.id.toString()">{{ user.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <div class="grid gap-2">
                <Label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Content</Label>
                <div class="border border-slate-200 rounded-md overflow-hidden focus-within:ring-2 focus-within:ring-indigo-500 focus-within:border-transparent transition-all">
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
                    <editor-content :editor="editor" />
                </div>
                <p v-if="form.errors.content" class="text-xs text-destructive">{{ form.errors.content }}</p>
            </div>
        </div>

        <div class="flex justify-end gap-3 px-6 py-4 bg-slate-50/50 border-t border-slate-100">
            <Button variant="outline" @click="emit('cancel')" class="px-6 font-bold">Cancel</Button>
            <Button @click="emit('submit')" :disabled="form.processing" class="bg-indigo-600 px-8 font-bold">
                <RefreshCw v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
                {{ mode === 'create' ? 'Save Item' : 'Update Item' }}
            </Button>
        </div>
    </div>
</template>

<style>
/* Ensure standard typography looks good inside the editor */
.prose ul { list-style-type: disc; padding-left: 1.5rem; }
.prose ol { list-style-type: decimal; padding-left: 1.5rem; }
</style>
