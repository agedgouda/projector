<script setup lang="ts">
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Bold, Italic, List, ListOrdered, Code, Copy, Sparkles } from 'lucide-vue-next';
import { EditorContent } from '@tiptap/vue-3';
import { useDocumentEditor } from '@/composables/useDocumentEditor';
import GeneratePromptsModal from './GeneratePromptsModal.vue';

const props = defineProps<{
    name: string;
    description: string | null;
    generationBrief: string | null;
    systemPrompt: string;
    userPrompt: string;
    singleOutput: boolean;
    outputKey: string | null;
    processing: boolean;
    errors: Record<string, string>;
    isEditing: boolean;
    // The same form, read-only — how TransformationSheet.vue shows a transformation before
    // anyone clicks Edit, so switching to editing just unlocks the fields in place.
    readonly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:name', value: string): void;
    (e: 'update:description', value: string): void;
    (e: 'update:generationBrief', value: string): void;
    (e: 'update:systemPrompt', value: string): void;
    (e: 'update:userPrompt', value: string): void;
    (e: 'update:singleOutput', value: boolean): void;
    (e: 'update:outputKey', value: string): void;
    (e: 'submit'): void;
    (e: 'cancel'): void;
}>();

const { editor } = useDocumentEditor(
    props.systemPrompt,
    (html) => emit('update:systemPrompt', html),
);

// Read-only until editing; and when the prompt is put back from outside (Discard reverting the
// form, or a save bringing back the stored copy) the editor follows without reporting it as an edit.
watch(
    [editor, () => props.readonly],
    // false: unlocking isn't an edit — TipTap would otherwise report its re-serialized HTML as one.
    ([instance, readonly]) => instance?.setEditable(!readonly, false),
    { immediate: true },
);
watch(
    () => props.systemPrompt,
    (value) => {
        if (editor.value && value !== editor.value.getHTML()) {
            editor.value.commands.setContent(value, { emitUpdate: false });
        }
    },
);

const copyToClipboard = (text: string | null) => {
    if (!text) return;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        const el = document.createElement('textarea');
        el.value = text;
        el.style.position = 'fixed';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    toast.success('Copied to clipboard');
};

const isGenerateModalOpen = ref(false);

// Mirrors the server's fallback (Str::slug($name, '_')) so a template always gets a safe,
// predictable document type key even if the user never touches the field themselves.
const slugify = (value: string): string => value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');

const applyGenerated = (payload: { brief: string; systemPrompt: string; userPrompt: string }) => {
    emit('update:generationBrief', payload.brief);
    // setContent triggers the editor's onUpdate, which emits update:systemPrompt itself.
    editor.value?.commands.setContent(payload.systemPrompt);
    emit('update:userPrompt', payload.userPrompt);

    // Only fill it in — never clobber a value the user already set on purpose.
    if (!props.outputKey?.trim() && props.name.trim()) {
        emit('update:outputKey', slugify(props.name));
    }

    isGenerateModalOpen.value = false;
};
</script>

<template>
    <form @submit.prevent="emit('submit')" class="space-y-8">
        <div class="grid gap-8 md:grid-cols-2">
        <div class="space-y-2">
            <div class="flex items-center gap-2 mb-1">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">Name</Label>
            </div>
            <Input
                :model-value="name"
                :readonly="readonly"
                @update:model-value="val => emit('update:name', val as string)"
                placeholder="e.g. Software: Note to User Story"
                class="h-12 rounded-xl border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/50 focus:ring-4 focus:ring-projector-primary-500/5"
            />
            <div v-if="errors.name" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ errors.name }}</div>
        </div>

        <div class="space-y-2">
            <div class="flex items-center gap-2 mb-1">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">Description</Label>
            </div>
            <Input
                :model-value="description ?? ''"
                :readonly="readonly"
                @update:model-value="val => emit('update:description', val as string)"
                placeholder="e.g. Turns raw meeting notes into a polished user story"
                class="h-12 rounded-xl border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/50 focus:ring-4 focus:ring-projector-primary-500/5"
            />
            <div v-if="errors.description" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ errors.description }}</div>
        </div>
        </div>

        <div class="grid gap-8 md:grid-cols-2">

        <div class="space-y-2">
            <div class="flex rounded-lg bg-gray-100 dark:bg-gray-900 p-0.5 text-[11px] font-black uppercase tracking-wider">
                <button
                    type="button"
                    class="flex-1 rounded-md py-2 transition-colors disabled:cursor-default"
                    :class="singleOutput ? 'bg-white dark:bg-gray-800 shadow-sm text-gray-900 dark:text-white' : 'text-gray-400'"
                    :disabled="readonly"
                    @click="emit('update:singleOutput', true)"
                >
                    Create Single Document
                </button>
                <button
                    type="button"
                    class="flex-1 rounded-md py-2 transition-colors disabled:cursor-default"
                    :class="!singleOutput ? 'bg-white dark:bg-gray-800 shadow-sm text-gray-900 dark:text-white' : 'text-gray-400'"
                    :disabled="readonly"
                    @click="emit('update:singleOutput', false)"
                >
                    Create a Document Set
                </button>
            </div>
            <p class="text-[11px] text-gray-400 px-1">
                Single Document produces one cohesive document (like a proposal or SOW). Document Set extracts a list of discrete items (like tasks or user stories).
            </p>
        </div>

        <div class="space-y-2">
            <div class="flex items-center gap-2 mb-1">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">Output Type Key</Label>
            </div>
            <Input
                :model-value="outputKey ?? ''"
                :readonly="readonly"
                @update:model-value="val => emit('update:outputKey', val as string)"
                placeholder="e.g. task, requirement, user_story"
                class="h-12 rounded-xl border-gray-200 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/50 focus:ring-4 focus:ring-projector-primary-500/5 font-mono"
            />
            <p class="text-[11px] text-gray-400 px-1">
                The document type this transformation's output gets tagged with — lowercase letters, numbers, and underscores only. Filled in automatically from the name when you generate with AI; leave it as-is unless you need the output to match an existing type (e.g. "task", so it shows checkboxes and an assignee).
            </p>
            <div v-if="errors.output_key" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ errors.output_key }}</div>
        </div>
        </div>

        <div class="space-y-2">
            <div class="flex h-8 items-center justify-between gap-2 mb-1">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">System Instructions (The Persona)</Label>
                <Transition name="fade-swap" mode="out-in">
                    <Button v-if="readonly" key="copy" type="button" variant="outline" size="sm" @click="copyToClipboard(systemPrompt)">
                        <Copy class="h-3.5 w-3.5" />
                        Copy
                    </Button>
                    <Button v-else key="generate" type="button" variant="outline" size="sm" @click="isGenerateModalOpen = true">
                        <Sparkles class="h-3.5 w-3.5" />
                        Generate with AI
                    </Button>
                </Transition>
            </div>
            <div class="min-h-[409px] border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden transition-all" :class="readonly ? '' : 'focus-within:ring-4 focus-within:ring-projector-primary-500/5 focus-within:border-projector-primary-500'">
                <div class="flex h-12 items-center gap-1 p-2 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-900/30">
                <Transition name="fade-swap">
                <div v-if="editor && !readonly" class="flex items-center gap-1">
                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleBold().run()" :class="{ 'bg-slate-200 dark:bg-white/20': editor.isActive('bold') }">
                        <Bold class="h-4 w-4" />
                    </Button>
                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleItalic().run()" :class="{ 'bg-slate-200 dark:bg-white/20': editor.isActive('italic') }">
                        <Italic class="h-4 w-4" />
                    </Button>
                    <div class="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1" />
                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleBulletList().run()" :class="{ 'bg-slate-200 dark:bg-white/20': editor.isActive('bulletList') }">
                        <List class="h-4 w-4" />
                    </Button>
                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleOrderedList().run()" :class="{ 'bg-slate-200 dark:bg-white/20': editor.isActive('orderedList') }">
                        <ListOrdered class="h-4 w-4" />
                    </Button>
                    <div class="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1" />
                    <Button type="button" variant="ghost" size="icon" class="h-8 w-8" @click="editor.chain().focus().toggleCode().run()" :class="{ 'bg-slate-200 dark:bg-white/20': editor.isActive('code') }" title="Inline code — use for HTML tag examples">
                        <Code class="h-4 w-4" />
                    </Button>
                </div>
                </Transition>
                </div>
                <EditorContent :editor="editor" class="[&_.ProseMirror]:min-h-[360px]" />
            </div>
            <div v-if="errors.system_prompt" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ errors.system_prompt }}</div>
        </div>

        <div class="space-y-2">
            <div class="flex h-8 items-center justify-between gap-2 mb-1">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">User Prompt (The Transformation)</Label>
                <Transition name="fade-swap">
                    <Button v-if="readonly" type="button" variant="outline" size="sm" @click="copyToClipboard(userPrompt)">
                        <Copy class="h-3.5 w-3.5" />
                        Copy
                    </Button>
                </Transition>
            </div>
            <div class="relative group">
                <textarea
                    :value="userPrompt"
                    :readonly="readonly"
                    @input="e => emit('update:userPrompt', (e.target as HTMLTextAreaElement).value)"
                    placeholder="Based on these notes: {{input}}, create a..."
                    class="w-full min-h-[320px] resize-y rounded-2xl border border-gray-200 dark:border-gray-800 bg-gray-50/30 dark:bg-gray-900/30 p-4 text-sm font-mono leading-relaxed outline-none focus:ring-4 focus:ring-projector-primary-500/5 focus:border-projector-primary-500 transition-all text-gray-700 dark:text-gray-300"
                />
            </div>
            <div v-if="errors.user_prompt" class="text-[10px] font-bold text-red-500 uppercase px-1">{{ errors.user_prompt }}</div>
        </div>

        <div v-if="readonly && generationBrief" class="space-y-2">
            <div class="flex h-8 items-center justify-between gap-2 mb-1">
                <Label class="text-[10px] font-black uppercase tracking-widest text-gray-400">Generation Prompt</Label>
                <Button type="button" variant="outline" size="sm" @click="copyToClipboard(generationBrief)">
                    <Copy class="h-3.5 w-3.5" />
                    Copy
                </Button>
            </div>
            <p class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-gray-50/30 dark:bg-gray-900/30 p-4 text-sm leading-relaxed whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ generationBrief }}</p>
        </div>

        <Transition name="fade-swap">
        <div v-if="!readonly" class="flex items-center justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-800">
            <Button type="button" @click="emit('cancel')" class="bg-white text-projector-primary-600 border border-projector-primary-600 font-black uppercase text-[10px] tracking-widest px-8 h-12 rounded-xl hover:bg-projector-primary-50 dark:bg-transparent dark:text-projector-primary-400 dark:border-projector-primary-400 dark:hover:bg-projector-primary-950/30">
                Cancel
            </Button>
            <Button
                type="submit"
                :disabled="processing"
                class="bg-projector-primary-600 hover:bg-projector-primary-700 text-white font-black uppercase text-[10px] tracking-widest px-8 h-12 rounded-xl shadow-lg"
            >
                {{ isEditing ? 'Save Changes' : 'Activate Intelligence' }}
            </Button>
        </div>
        </Transition>

        <GeneratePromptsModal
            v-if="!readonly"
            :open="isGenerateModalOpen"
            :initial-brief="generationBrief"
            :has-existing-prompts="!!(systemPrompt.trim() || userPrompt.trim())"
            @close="isGenerateModalOpen = false"
            @apply="applyGenerated"
        />
    </form>
</template>
