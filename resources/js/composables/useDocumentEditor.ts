import { onBeforeUnmount, watch, type Ref } from 'vue';
import { useEditor } from '@tiptap/vue-3';
import { mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Mention from '@tiptap/extension-mention';
import tippy, { type Instance as TippyInstance } from 'tippy.js';
import { createApp, h } from 'vue';
import MentionList from '@/components/comments/MentionList.vue';

type MentionUser = { id: number; name: string; first_name: string; last_name: string };

function buildMentionSuggestion(users: MentionUser[] | Ref<MentionUser[]>) {
    return {
        items: ({ query }: { query: string }) => {
            const list = Array.isArray(users) ? users : users.value ?? [];
            return list
                .filter(u => u.name.toLowerCase().includes(query.toLowerCase()))
                .slice(0, 8);
        },

        render: () => {
            let component: ReturnType<typeof createApp> | null = null;
            let popup: TippyInstance[] | null = null;
            let mountEl: HTMLElement | null = null;
            let listRef: InstanceType<typeof MentionList> | null = null;

            return {
                onStart: (props: any) => {
                    mountEl = document.createElement('div');
                    component = createApp({
                        render: () => h(MentionList, { ...props, ref: 'list' }),
                    });
                    const instance = component.mount(mountEl) as any;
                    listRef = instance.$refs?.list ?? null;

                    popup = tippy('body', {
                        getReferenceClientRect: props.clientRect,
                        appendTo: () => document.body,
                        content: mountEl,
                        showOnCreate: true,
                        interactive: true,
                        trigger: 'manual',
                        placement: 'bottom-start',
                        theme: 'mention',
                    });
                },

                onUpdate: (props: any) => {
                    component?.provide('props', props);
                    popup?.[0]?.setProps({ getReferenceClientRect: props.clientRect });
                },

                onKeyDown: (props: any) => {
                    if (props.event.key === 'Escape') {
                        popup?.[0]?.hide();
                        return true;
                    }
                    return listRef?.onKeyDown(props.event) ?? false;
                },

                onExit: () => {
                    popup?.[0]?.destroy();
                    component?.unmount();
                    mountEl?.remove();
                    listRef = null;
                },
            };
        },
    };
}

export function useDocumentEditor(
    content: string | null | undefined,
    onUpdate: (html: string) => void,
    users?: MentionUser[] | Ref<MentionUser[]>,
) {
    const extensions: any[] = [StarterKit];

    if (users) {
        extensions.push(
            Mention.extend({
                renderHTML({ node, HTMLAttributes }) {
                    const name = String(node.attrs.label ?? node.attrs.id ?? '');
                    const initials = name.trim().split(' ').map((p: string) => p[0] ?? '').join('').toUpperCase().slice(0, 2);
                    return [
                        'span',
                        mergeAttributes({ class: 'mention' }, HTMLAttributes),
                        ['span', { class: 'mention-avatar' }, initials],
                        name,
                    ];
                },
            }).configure({
                HTMLAttributes: {},
                suggestion: buildMentionSuggestion(users),
            }),
        );
    }

    const editor = useEditor({
        content: content || '',
        extensions,
        editorProps: {
            attributes: {
                class: 'w-full min-h-[200px] bg-white text-sm border-slate-200 focus:outline-none leading-relaxed p-4 prose prose-sm max-w-none',
            },
        },
        onUpdate: ({ editor }) => {
            onUpdate(editor.getHTML());
        },
    });

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
