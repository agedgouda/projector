<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { RefreshCw, Bold, Italic, List, ListOrdered, X, Plus, CheckCircle2 } from 'lucide-vue-next';
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
    form: InertiaForm<DocumentForm>;
    document_schema?: DocumentSchemaItem[]; // Added project prop to get schema
}>();

const emit = defineEmits(['cancel', 'submit']);

/**
 * Correct mapping for the Select dropdown
 */


// Use the new composable
const { editor } = useDocumentEditor(
    props.form.content,
    (html) => updateField('content', html)
);

const updateField = (field: string, value: any) => {
    (props.form as any)[field] = value;
};

// Helper to ensure criteria metadata structure exists
const initializeMetadata = () => {
    if (!props.form.metadata) {
        updateField('metadata', { criteria: [] });
    } else if (typeof props.form.metadata === 'string') {
        try {
            updateField('metadata', JSON.parse(props.form.metadata));
        } catch {
            updateField('metadata', { criteria: [] });
        }
    }
};

initializeMetadata();

/**
 * Metadata Helpers
 */
const updateMetadata = (callback: (criteria: string[]) => string[]) => {
    const currentMetadata = { ...(props.form.metadata || { criteria: [], raw_data: {} }) };
    const updatedCriteria = callback([...(currentMetadata.criteria || [])]);

    const updatedMetadata = {
        ...currentMetadata,
        criteria: updatedCriteria,
        raw_data: {
            ...currentMetadata.raw_data,
            criteria: updatedCriteria
        }
    };

    updateField('metadata', updatedMetadata);
};

const addCriterion = () =>
    updateMetadata((criteria) => [...criteria, '']);

const removeCriterion = (index: number) =>
    updateMetadata((criteria) => criteria.filter((_, i) => i !== index));

const updateCriterion = (index: number, value: string) =>
    updateMetadata((criteria) => {
        const next = [...criteria];
        next[index] = value;
        return next;
    });
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col md:flex-row gap-6">
            <div class="flex-[3] grid gap-2">
                <Label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Document Name</Label>
                <Input
                    :model-value="form.name ?? ''"
                    @update:model-value="(v) => updateField('name', v)"
                    class="bg-white h-11 border-slate-200"
                />
                <p v-if="form.errors.name" class="text-[10px] text-red-500 font-bold uppercase">{{ form.errors.name }}</p>
            </div>

            <div v-if="mode === 'create'" class="flex-1 min-w-[180px] grid gap-2">
                <Label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Category</Label>
                <Select :model-value="form.type" @update:model-value="(v) => updateField('type', v)">
                    <SelectTrigger class="bg-white h-11 border-slate-200">
                        <SelectValue placeholder="Select..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="doc in document_schema" :key="doc.key" :value="doc.key">
                            {{ doc.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p v-if="form.errors.type" class="text-[10px] text-red-500 font-bold uppercase">{{ form.errors.type }}</p>
            </div>
        </div>

        <div class="grid gap-2">
            <Label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Content</Label>
            <div class="border border-slate-200 rounded-md overflow-hidden focus-within:ring-2 focus-within:ring-indigo-500 focus-within:border-transparent transition-all">
                <div v-if="editor" class="flex items-center gap-1 p-2 border-b border-slate-100 bg-slate-50/50">
                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleBold().run()" :class="{ 'bg-slate-200': editor.isActive('bold') }">
                        <Bold class="h-4 w-4" />
                    </Button>
                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleItalic().run()" :class="{ 'bg-slate-200': editor.isActive('italic') }">
                        <Italic class="h-4 w-4" />
                    </Button>
                    <div class="w-px h-4 bg-slate-200 mx-1"></div>
                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleBulletList().run()" :class="{ 'bg-slate-200': editor.isActive('bulletList') }">
                        <List class="h-4 w-4" />
                    </Button>
                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleOrderedList().run()" :class="{ 'bg-slate-200': editor.isActive('orderedList') }">
                        <ListOrdered class="h-4 w-4" />
                    </Button>
                </div>
                <editor-content :editor="editor" />
            </div>
            <p v-if="form.errors.content" class="text-[10px] text-red-500 font-bold uppercase pt-1">{{ form.errors.content }}</p>
        </div>

        <div class="grid gap-4 pt-4 border-t border-slate-100">
            <div class="flex items-center justify-between">
                <div class="space-y-1">
                    <Label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Success Criteria</Label>
                    <p class="text-[10px] text-slate-400 font-medium">Define what 'done' looks like for this story.</p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addCriterion"
                    class="h-7 text-[9px] font-black uppercase tracking-widest text-indigo-600 border-indigo-100 hover:bg-indigo-50"
                >
                    <Plus class="h-3 w-3 mr-1" /> Add Item
                </Button>
            </div>

            <div class="space-y-2">
                <div
                    v-for="(criterion, index) in form.metadata?.criteria"
                    :key="index"
                    class="flex items-center gap-2 group"
                >
                    <div class="flex-none">
                        <CheckCircle2 class="h-4 w-4 text-emerald-400 opacity-50" />
                    </div>

                    <Input
                        :model-value="criterion"
                        @update:model-value="(v) => updateCriterion(Number(index), String(v))"
                        placeholder="Requirement..."
                        class="bg-white h-10 border-slate-200 flex-1 text-[13px] focus-visible:ring-indigo-500"
                    />

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        @click="removeCriterion(Number(index))"
                        class="h-8 w-8 text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors"
                    >
                        <X class="h-3.5 w-3.5" />
                    </Button>
                </div>

                <div
                    v-if="!form.metadata?.criteria?.length"
                    @click="addCriterion"
                    class="border border-dashed border-slate-200 rounded-xl p-6 flex flex-col items-center justify-center cursor-pointer hover:border-indigo-200 hover:bg-indigo-50/30 transition-all group"
                >
                    <Plus class="h-5 w-5 text-slate-300 group-hover:text-indigo-400 mb-1" />
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">No criteria defined</span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-3 px-6 py-4 bg-slate-50/50 border-t border-slate-100 mt-6 rounded-b-2xl">
        <Button variant="outline" @click="emit('cancel')" class="px-6 font-bold uppercase text-[10px] tracking-widest">
            Cancel
        </Button>
        <Button @click="emit('submit')" :disabled="form.processing" class="bg-indigo-600 hover:bg-indigo-700 px-8 font-bold uppercase text-[10px] tracking-widest">
            <RefreshCw v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
            {{ mode === 'create' ? 'Create Document' : 'Update Document' }}
        </Button>
    </div>
</template>
