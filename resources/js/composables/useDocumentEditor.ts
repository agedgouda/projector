
import { onBeforeUnmount, watch } from 'vue';
import { useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';

export function useDocumentEditor(content: string | null | undefined, onUpdate: (html: string) => void) {
    const editor = useEditor({
        content: content || '',
        extensions: [StarterKit],
        editorProps: {
            attributes: {
                class: 'w-full min-h-[200px] bg-white text-sm border-slate-200 focus:outline-none leading-relaxed p-4 prose prose-sm max-w-none',
            },
        },
        onUpdate: ({ editor }) => {
            onUpdate(editor.getHTML());
        },
    });

    // Watch for external changes (e.g., when the form resets or a new doc is loaded)
    watch(() => content, (newContent) => {
        if (editor.value && newContent !== editor.value.getHTML()) {
            editor.value.commands.setContent(newContent || '', { emitUpdate: false });
        }
    });

    onBeforeUnmount(() => {
        editor.value?.destroy();
    });

    return { editor };
}
