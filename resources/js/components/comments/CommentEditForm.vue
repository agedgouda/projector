<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useDocumentEditor } from '@/composables/useDocumentEditor';
import { EditorContent } from '@tiptap/vue-3';
import { Bold, Italic, List, ListOrdered, Loader2, Paperclip } from 'lucide-vue-next';
import { ref } from 'vue';

// Same toolbar/editor shape as CommentSection.vue's own new-comment composer — kept as a
// separate component (rather than a mode toggle inside CommentSection.vue) because each
// comment being edited needs its own Tiptap instance, and useDocumentEditor() can only be
// called once per component setup.
const props = defineProps<{
    body: string;
    mentionableUsers?: {
        id: number | string;
        name: string;
        first_name: string;
        last_name: string;
    }[];
    projectId: string;
    saving: boolean;
}>();

const emit = defineEmits<{
    save: [html: string];
    cancel: [];
}>();

const draft = ref(props.body);

const { editor, triggerUpload, isUploading } = useDocumentEditor(
    props.body,
    (html) => { draft.value = html; },
    props.mentionableUsers ?? [],
    props.projectId,
);

const save = () => {
    if (!draft.value.trim() || props.saving || isUploading.value) return;
    emit('save', draft.value);
};
</script>

<template>
    <div @keydown.meta.enter="save" @keydown.ctrl.enter="save">
        <div
            class="overflow-hidden rounded-md border border-slate-300 transition-all focus-within:border-transparent focus-within:ring-2 focus-within:ring-projector-primary-500 dark:border-slate-700 dark:bg-white/5"
        >
            <div
                v-if="editor"
                class="flex items-center gap-1 border-b border-slate-100 bg-slate-50/50 p-2 dark:border-slate-700 dark:bg-white/5"
            >
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8 dark:text-slate-300"
                    @click="editor.chain().focus().toggleBold().run()"
                    :class="{
                        'bg-slate-200 dark:bg-white/20': editor.isActive('bold'),
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
                        'bg-slate-200 dark:bg-white/20': editor.isActive('italic'),
                    }"
                >
                    <Italic class="h-4 w-4" />
                </Button>
                <div class="mx-1 h-4 w-px bg-slate-200 dark:bg-slate-700"></div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8 dark:text-slate-300"
                    @click="editor.chain().focus().toggleBulletList().run()"
                    :class="{
                        'bg-slate-200 dark:bg-white/20': editor.isActive('bulletList'),
                    }"
                >
                    <List class="h-4 w-4" />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8 dark:text-slate-300"
                    @click="editor.chain().focus().toggleOrderedList().run()"
                    :class="{
                        'bg-slate-200 dark:bg-white/20': editor.isActive('orderedList'),
                    }"
                >
                    <ListOrdered class="h-4 w-4" />
                </Button>
                <div class="mx-1 h-4 w-px bg-slate-200 dark:bg-slate-700"></div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8 dark:text-slate-300"
                    @click="triggerUpload"
                >
                    <Paperclip class="h-4 w-4" />
                </Button>
            </div>
            <editor-content :editor="editor" />
        </div>
        <div class="mt-2 flex items-center justify-end gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="h-7 text-[10px] font-bold tracking-widest uppercase"
                @click="emit('cancel')"
            >
                Cancel
            </Button>
            <Button
                type="button"
                size="sm"
                :disabled="!draft.trim() || saving || isUploading"
                class="h-7 rounded-lg bg-projector-primary-600 px-4 text-[10px] font-bold tracking-widest uppercase hover:bg-projector-primary-700"
                @click="save"
            >
                <Loader2 v-if="saving || isUploading" class="mr-1 h-3 w-3 animate-spin" />
                {{ isUploading ? 'Uploading…' : 'Save' }}
            </Button>
        </div>
    </div>
</template>
