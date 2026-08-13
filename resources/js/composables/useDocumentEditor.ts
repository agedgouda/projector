import MentionList from '@/components/comments/MentionList.vue';
import contentUploads from '@/routes/projects/content-uploads';
import { mergeAttributes, Node } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import Mention from '@tiptap/extension-mention';
import StarterKit from '@tiptap/starter-kit';
import { useEditor } from '@tiptap/vue-3';
import axios, { type AxiosError } from 'axios';
import tippy, { type Instance as TippyInstance } from 'tippy.js';
import { computed, createApp, h, onBeforeUnmount, reactive, ref, watch, type Ref } from 'vue';
import { toast } from 'vue-sonner';

// Kept in sync with the production server's own hard upload cap (its web server/PHP config
// rejects anything larger before the request even reaches the app) — see
// ContentUploadController::store()'s matching `max:2048` (KB) validation rule. Checked
// client-side first so an oversized file gets a clear, specific error instead of a generic
// failure once it hits that ceiling.
const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

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
    id: number | string;
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
            // Wrapped in reactive() so the render() below re-runs on every keystroke with
            // the latest `items`/`command`/`clientRect` — onUpdate() just mutates this in
            // place. Previously onUpdate() called component.provide('props', props), but
            // MentionList never injects a 'props' value, so that was a no-op: the list stayed
            // frozen on whatever unfiltered items came in at the very first keystroke, and
            // its `command` stayed bound to that same first keystroke's (now stale) replace
            // range — which is what let a selection insert the mention but leave the rest of
            // the typed query text behind as ordinary text.
            let state: any = null;

            return {
                onStart: (props: any) => {
                    state = reactive({ ...props });
                    mountEl = document.createElement('div');
                    component = createApp({
                        render: () => h(MentionList, { ...state, ref: 'list' }),
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
                    Object.assign(state!, props);
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
                    state = null;
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

    // Tracks in-flight uploads so callers can block save/submit until every upload has
    // actually landed in the editor's content — without this, saving while an upload is
    // still pending silently persists content with the image missing, since the upload's
    // own insertion into the editor happens asynchronously after the save already ran.
    const pendingUploads = ref(0);
    const isUploading = computed(() => pendingUploads.value > 0);

    // Only wired up when a projectId is given — a couple of unrelated editors elsewhere
    // reuse this composable without one (e.g. AiTemplateForm.vue), and file upload has no
    // authorization scope to upload against without a project.
    function uploadFile(file: File) {
        if (!projectId) {
            return;
        }

        if (file.size > MAX_UPLOAD_BYTES) {
            const sizeMb = (file.size / (1024 * 1024)).toFixed(1);
            toast.error(`${file.name} is too large (${sizeMb}MB) — the maximum upload size is 2MB.`);
            return;
        }

        const formData = new FormData();
        formData.append('file', file);

        pendingUploads.value++;

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
            })
            .catch((error: AxiosError<{ message?: string }>) => {
                // The server response is often the only record of what actually went wrong
                // (e.g. a hard rejection at the production web server/PHP layer never reaches
                // the app's own logging) — captured here, best-effort, so it's still visible
                // later even though the user only ever sees the generic toast below.
                console.error('Content upload failed', error);
                axios
                    .post('/log-upload-error', {
                        project_id: projectId,
                        file_name: file.name,
                        file_size: file.size,
                        file_type: file.type,
                        status: error.response?.status ?? null,
                        message: error.response?.data?.message ?? error.message,
                    })
                    .catch(() => {
                        // Never let logging itself mask the original upload failure.
                    });
                throw error;
            })
            .finally(() => {
                pendingUploads.value--;
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

    return { editor, triggerUpload, isUploading };
}
