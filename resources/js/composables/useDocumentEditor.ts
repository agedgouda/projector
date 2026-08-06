import MentionList from '@/components/comments/MentionList.vue';
import contentUploads from '@/routes/projects/content-uploads';
import { mergeAttributes, Node } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import Mention from '@tiptap/extension-mention';
import StarterKit from '@tiptap/starter-kit';
import { useEditor } from '@tiptap/vue-3';
import axios from 'axios';
import tippy, { type Instance as TippyInstance } from 'tippy.js';
import { createApp, h, onBeforeUnmount, watch, type Ref } from 'vue';
import { toast } from 'vue-sonner';

// Non-image uploads (any file type is accepted) render as this atomic inline chip rather
// than a plain link, so they're visually distinct from ordinary text links produced by
// StarterKit's own bundled Link mark — parseHTML is scoped to `a.file-attachment[href]`
// specifically so the two don't collide when re-parsing saved HTML.
const FileAttachment = Node.create({
    name: 'fileAttachment',
    group: 'inline',
    inline: true,
    atom: true,

    addAttributes() {
        // rendered: false — both are written out explicitly in renderHTML below (as `href`
        // and via the `download` attribute), so they shouldn't also be auto-rendered as
        // literal `href`/`name` HTML attributes by Tiptap's default per-attribute behavior.
        return {
            href: { default: null, rendered: false },
            name: { default: null, rendered: false },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'a.file-attachment[href]',
                getAttrs: (el) => ({
                    href: (el as HTMLElement).getAttribute('href'),
                    name: (el as HTMLElement).textContent,
                }),
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            'a',
            mergeAttributes(
                {
                    class: 'file-attachment',
                    href: node.attrs.href,
                    download: node.attrs.name,
                    rel: 'noopener noreferrer',
                },
                HTMLAttributes,
            ),
            node.attrs.name,
        ];
    },
});

type MentionUser = {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
};

function buildMentionSuggestion(users: MentionUser[] | Ref<MentionUser[]>) {
    return {
        items: ({ query }: { query: string }) => {
            const list = Array.isArray(users) ? users : (users.value ?? []);
            return list
                .filter((u) =>
                    u.name.toLowerCase().includes(query.toLowerCase()),
                )
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
                    popup?.[0]?.setProps({
                        getReferenceClientRect: props.clientRect,
                    });
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
    content: string | null | undefined | (() => string | null | undefined),
    onUpdate: (html: string) => void,
    users?: MentionUser[] | Ref<MentionUser[]>,
    projectId?: string,
) {
    // Accepting a getter (rather than only a plain string) lets the watch below track the
    // live source value — e.g. a reactive form field — instead of a one-off snapshot taken
    // when this composable was called. Without that, external resets (like `form.reset()`
    // after "Save and New", where the same component instance is reused rather than
    // remounted) never reach the editor's actual displayed content.
    const getContent = () =>
        typeof content === 'function' ? content() : content;

    const extensions: any[] = [StarterKit, Image.configure({ inline: true }), FileAttachment];

    if (users) {
        extensions.push(
            Mention.extend({
                // The base extension's own parseHTML() only matches
                // span[data-type="mention"] — widened here to also match the plain
                // span.mention this app saved before the renderHTML fix below existed, so
                // mentions saved by that earlier version still round-trip correctly instead
                // of degrading into inert HTML the next time their document is opened.
                parseHTML() {
                    return [
                        { tag: 'span[data-type="mention"]' },
                        { tag: 'span.mention' },
                    ];
                },
                renderHTML({ node, HTMLAttributes }) {
                    const name = String(
                        node.attrs.label ?? node.attrs.id ?? '',
                    );
                    const initials = name
                        .trim()
                        .split(' ')
                        .map((p: string) => p[0] ?? '')
                        .join('')
                        .toUpperCase()
                        .slice(0, 2);
                    return [
                        'span',
                        // Overriding renderHTML replaces the base Mention extension's own
                        // implementation entirely, which is what normally stamps
                        // data-type="mention" onto the span for parseHTML() (above) to find
                        // on the next load — added explicitly here since our own version
                        // wouldn't otherwise include it.
                        mergeAttributes(
                            { class: 'mention', 'data-type': 'mention' },
                            HTMLAttributes,
                        ),
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

    // Only wired up when a projectId is given — a couple of unrelated editors elsewhere
    // reuse this composable without one (e.g. AiTemplateForm.vue), and file upload has no
    // authorization scope to upload against without a project.
    function uploadFile(file: File) {
        if (!projectId) {
            return;
        }

        const formData = new FormData();
        formData.append('file', file);

        const upload = axios
            .post(contentUploads.store.url({ project: projectId }), formData)
            .then(({ data }: { data: { url: string; name: string } }) => {
                if (!editor.value) {
                    return;
                }
                if (file.type.startsWith('image/')) {
                    editor.value
                        .chain()
                        .focus()
                        .setImage({ src: data.url, alt: data.name })
                        .run();
                } else {
                    editor.value
                        .chain()
                        .focus()
                        .insertContent({
                            type: 'fileAttachment',
                            attrs: { href: data.url, name: data.name },
                        })
                        .run();
                }
            });

        toast.promise(upload, {
            loading: `Uploading ${file.name}...`,
            success: `${file.name} uploaded`,
            error: `Failed to upload ${file.name}`,
        });
    }

    function triggerUpload() {
        if (!projectId) {
            return;
        }

        const input = document.createElement('input');
        input.type = 'file';
        input.multiple = true;
        input.onchange = () => {
            Array.from(input.files ?? []).forEach(uploadFile);
        };
        input.click();
    }

    const editor = useEditor({
        content: getContent() || '',
        extensions,
        editorProps: {
            attributes: {
                class: 'w-full min-h-[200px] bg-white text-sm border-slate-200 focus:outline-none leading-relaxed p-4 prose prose-sm max-w-none',
            },
            handleDrop: (_view, event) => {
                const files = (event as DragEvent).dataTransfer?.files;
                if (!projectId || !files || files.length === 0) {
                    return false;
                }
                event.preventDefault();
                Array.from(files).forEach(uploadFile);
                return true;
            },
            handlePaste: (_view, event) => {
                const files = (event as ClipboardEvent).clipboardData?.files;
                if (!projectId || !files || files.length === 0) {
                    return false;
                }
                event.preventDefault();
                Array.from(files).forEach(uploadFile);
                return true;
            },
        },
        onUpdate: ({ editor }) => {
            onUpdate(editor.getHTML());
        },
    });

    watch(getContent, (newContent) => {
        if (editor.value && newContent !== editor.value.getHTML()) {
            editor.value.commands.setContent(newContent || '', {
                emitUpdate: false,
            });
        }
    });

    onBeforeUnmount(() => {
        editor.value?.destroy();
    });

    return { editor, triggerUpload };
}
