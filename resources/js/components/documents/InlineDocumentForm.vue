<script setup lang="ts">
import { useDocumentEditor } from '@/composables/useDocumentEditor';
import type { InertiaForm } from '@inertiajs/vue3';
import { EditorContent } from '@tiptap/vue-3';
import {
    Bold,
    CheckCircle2,
    Italic,
    List,
    ListOrdered,
    Plus,
    RefreshCw,
    X,
} from 'lucide-vue-next';

// UI Components
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
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
    () => props.form.content,
    (html) => updateField('content', html),
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
    const currentMetadata = {
        ...(props.form.metadata || { criteria: [], raw_data: {} }),
    };
    const updatedCriteria = callback([...(currentMetadata.criteria || [])]);

    const updatedMetadata = {
        ...currentMetadata,
        criteria: updatedCriteria,
        raw_data: {
            ...currentMetadata.raw_data,
            criteria: updatedCriteria,
        },
    };

    updateField('metadata', updatedMetadata);
};

const addCriterion = () => updateMetadata((criteria) => [...criteria, '']);

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
        <div class="flex flex-col gap-6 md:flex-row">
            <div class="grid flex-[3] gap-2">
                <Label
                    class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                    >Document Name</Label
                >
                <Input
                    :model-value="form.name ?? ''"
                    @update:model-value="(v) => updateField('name', v)"
                    class="h-11 border-slate-200 bg-white dark:border-white/10 dark:bg-white/5 dark:text-slate-200"
                />
                <p
                    v-if="form.errors.name"
                    class="text-[10px] font-bold text-red-500 uppercase"
                >
                    {{ form.errors.name }}
                </p>
            </div>

            <div v-if="mode === 'create'" class="grid flex-1 gap-2">
                <Label
                    class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                    >Category</Label
                >
                <Select
                    :model-value="form.type"
                    @update:model-value="(v) => updateField('type', v)"
                >
                    <SelectTrigger
                        class="!h-11 w-full !border-slate-200 bg-white dark:!border-white/10 dark:!bg-white/10 dark:text-slate-200"
                    >
                        <SelectValue placeholder="Select..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="doc in document_schema"
                            :key="doc.key"
                            :value="doc.key"
                        >
                            {{ doc.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p
                    v-if="form.errors.type"
                    class="text-[10px] font-bold text-red-500 uppercase"
                >
                    {{ form.errors.type }}
                </p>
            </div>
        </div>

        <div class="grid gap-2">
            <Label
                class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                >Content</Label
            >
            <div
                class="overflow-hidden rounded-md border border-slate-200 transition-all focus-within:border-transparent focus-within:ring-2 focus-within:ring-projector-primary-500 dark:border-white/10 dark:bg-white/5"
            >
                <div
                    v-if="editor"
                    class="flex items-center gap-1 border-b border-slate-100 bg-slate-50/50 p-2 dark:border-white/10 dark:bg-white/5"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 dark:text-slate-300"
                        @click="editor.chain().focus().toggleBold().run()"
                        :class="{
                            'bg-slate-200 dark:bg-white/20':
                                editor.isActive('bold'),
                        }"
                    >
                        <Bold class="h-4 w-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 dark:text-slate-300"
                        @click="editor.chain().focus().toggleItalic().run()"
                        :class="{
                            'bg-slate-200 dark:bg-white/20':
                                editor.isActive('italic'),
                        }"
                    >
                        <Italic class="h-4 w-4" />
                    </Button>
                    <div
                        class="mx-1 h-4 w-px bg-slate-200 dark:bg-white/20"
                    ></div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 dark:text-slate-300"
                        @click="editor.chain().focus().toggleBulletList().run()"
                        :class="{
                            'bg-slate-200 dark:bg-white/20':
                                editor.isActive('bulletList'),
                        }"
                    >
                        <List class="h-4 w-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 dark:text-slate-300"
                        @click="
                            editor.chain().focus().toggleOrderedList().run()
                        "
                        :class="{
                            'bg-slate-200 dark:bg-white/20':
                                editor.isActive('orderedList'),
                        }"
                    >
                        <ListOrdered class="h-4 w-4" />
                    </Button>
                </div>
                <editor-content :editor="editor" />
            </div>
            <p
                v-if="form.errors.content"
                class="pt-1 text-[10px] font-bold text-red-500 uppercase"
            >
                {{ form.errors.content }}
            </p>
        </div>

        <div
            class="grid gap-4 border-t border-slate-100 pt-4 dark:border-white/10"
        >
            <div class="flex items-center justify-between">
                <div class="space-y-1">
                    <Label
                        class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                        >Success Criteria</Label
                    >
                    <p class="text-[10px] font-medium text-slate-400">
                        Define what 'done' looks like for this story.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addCriterion"
                    class="h-7 border-projector-primary-100 text-[9px] font-black tracking-widest text-projector-primary-600 uppercase hover:bg-projector-primary-50"
                >
                    <Plus class="mr-1 h-3 w-3" /> Add Item
                </Button>
            </div>

            <div class="space-y-2">
                <div
                    v-for="(criterion, index) in form.metadata?.criteria"
                    :key="index"
                    class="group flex items-center gap-2"
                >
                    <div class="flex-none">
                        <CheckCircle2
                            class="h-4 w-4 text-emerald-400 opacity-50"
                        />
                    </div>

                    <Input
                        :model-value="criterion"
                        @update:model-value="
                            (v) => updateCriterion(Number(index), String(v))
                        "
                        placeholder="Requirement..."
                        class="h-10 flex-1 border-slate-200 bg-white text-[13px] focus-visible:ring-projector-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-200"
                    />

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        @click="removeCriterion(Number(index))"
                        class="h-8 w-8 text-slate-300 transition-colors hover:bg-red-50 hover:text-red-500"
                    >
                        <X class="h-3.5 w-3.5" />
                    </Button>
                </div>

                <div
                    v-if="!form.metadata?.criteria?.length"
                    @click="addCriterion"
                    class="group flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 p-6 transition-all hover:border-projector-primary-200 hover:bg-projector-primary-50/30 dark:border-white/10 dark:hover:bg-white/5"
                >
                    <Plus
                        class="mb-1 h-5 w-5 text-slate-300 group-hover:text-projector-primary-400"
                    />
                    <span
                        class="text-[10px] font-bold tracking-widest text-slate-400 uppercase"
                        >No criteria defined</span
                    >
                </div>
            </div>
        </div>
    </div>

    <div class="mt-6 flex justify-end gap-3 px-6 py-4">
        <Button
            @click="emit('cancel')"
            class="border border-projector-primary-600 bg-white px-6 text-[10px] font-bold tracking-widest text-projector-primary-600 uppercase hover:bg-projector-primary-50 dark:border-projector-primary-400 dark:bg-transparent dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30"
        >
            Cancel
        </Button>
        <Button
            @click="emit('submit')"
            :disabled="form.processing"
            class="bg-projector-primary-600 px-8 text-[10px] font-bold tracking-widest uppercase hover:bg-projector-primary-700"
        >
            <RefreshCw
                v-if="form.processing"
                class="mr-2 h-4 w-4 animate-spin"
            />
            {{ mode === 'create' ? 'Create Document' : 'Update Document' }}
        </Button>
    </div>
</template>
