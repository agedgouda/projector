<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDocumentEditor } from '@/composables/useDocumentEditor';
import CommentRoutes from '@/routes/comments/index';
import { useForm, usePage } from '@inertiajs/vue3';
import { EditorContent } from '@tiptap/vue-3';
import DOMPurify from 'dompurify';
import {
    Bold,
    Clock,
    Italic,
    List,
    ListOrdered,
    Loader2,
    MessageSquare,
    MoreVertical,
    Paperclip,
    Send,
    Trash2,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

const props = defineProps<{
    comments: Comment[];
    commentableType: 'task' | 'document';
    commentableId: string | number;
    mentionableUsers?: {
        id: number | string;
        name: string;
        first_name: string;
        last_name: string;
    }[];
    readOnly?: boolean;
    projectId: string;
}>();

const page = usePage<AppPageProps>();
const currentUserId = page.props.auth.user.id;

const scrollContainer = ref<HTMLElement | null>(null);

// Comments arrive oldest-first from the backend; "Newest First" just reverses a copy for
// display, since new comments still need to be posted/received in chronological order.
type CommentSortOrder = 'oldest' | 'newest';
const sortOrder = ref<CommentSortOrder>('oldest');

const sortedComments = computed(() =>
    sortOrder.value === 'newest'
        ? [...props.comments].reverse()
        : props.comments,
);

const form = useForm({
    body: '',
    type: props.commentableType,
    id: props.commentableId,
});

const { editor, triggerUpload, isUploading } = useDocumentEditor(
    '',
    (html) => {
        form.body = html;
    },
    props.mentionableUsers ?? [],
    props.projectId,
);

const scrollToBottom = () => {
    if (scrollContainer.value) {
        scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
    }
};

const scrollToTop = () => {
    if (scrollContainer.value) {
        scrollContainer.value.scrollTop = 0;
    }
};

// New comments are always appended chronologically, so they land at the bottom when sorted
// oldest-first and at the top when sorted newest-first — scroll to wherever that is.
const scrollToNewest = () => {
    if (sortOrder.value === 'newest') {
        scrollToTop();
    } else {
        scrollToBottom();
    }
};

onMounted(() => scrollToNewest());

watch(
    () => props.comments.length,
    () => {
        nextTick(() => scrollToNewest());
    },
);

watch(
    () => props.commentableId,
    (newId) => {
        form.id = newId;
    },
);

const submitComment = () => {
    if (!form.body.trim() || form.processing || isUploading.value) return;
    form.post(CommentRoutes.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('body');
            editor.value?.commands.setContent('', { emitUpdate: false });
        },
    });
};

const deleteComment = (id: number) => {
    if (!confirm('Are you sure you want to delete this comment?')) return;
    form.delete(CommentRoutes.destroy(id).url, {
        preserveScroll: true,
        onSuccess: () => toast.success('Comment deleted'),
    });
};

const timeAgo = (date: string) => {
    const seconds = Math.floor(
        (new Date().getTime() - new Date(date).getTime()) / 1000,
    );
    if (seconds < 5) return 'just now';
    if (seconds < 60) return `${seconds}s ago`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    return new Date(date).toLocaleDateString();
};

const sanitize = (html: string) => DOMPurify.sanitize(html);
</script>

<template>
    <div class="flex h-full flex-col overflow-hidden">
        <div
            v-if="comments.length > 0"
            class="flex shrink-0 items-center justify-end pb-3"
        >
            <Select v-model="sortOrder">
                <SelectTrigger
                    class="h-7 w-[130px] text-[10px] font-bold tracking-widest uppercase"
                >
                    <SelectValue placeholder="Sort" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="oldest">Oldest First</SelectItem>
                    <SelectItem value="newest">Newest First</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div
            ref="scrollContainer"
            class="custom-scrollbar flex-1 space-y-6 overflow-y-auto pb-6"
        >
            <div
                v-if="comments.length === 0"
                class="mt-4 rounded-3xl border-2 border-dashed border-slate-100 py-12 text-center dark:border-slate-800"
            >
                <MessageSquare class="mx-auto mb-3 h-8 w-8 text-slate-300" />
                <p
                    class="text-xs font-medium tracking-widest text-slate-700 uppercase"
                >
                    No discussion yet
                </p>
            </div>

            <div
                v-for="comment in sortedComments"
                :key="comment.id"
                class="group flex gap-3 pr-2"
            >
                <div
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-projector-primary-100 bg-projector-primary-50 text-[10px] font-black text-projector-primary-600 dark:border-projector-primary-900 dark:bg-projector-primary-950 dark:text-projector-primary-400"
                >
                    {{ comment.user?.first_name?.[0]
                    }}{{ comment.user?.last_name?.[0] }}
                </div>

                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span
                                class="text-xs font-bold text-slate-900 dark:text-slate-100"
                                >{{ comment.user?.name }}</span
                            >
                            <span
                                class="flex items-center gap-1 text-[10px] text-slate-400"
                            >
                                <Clock class="h-3 w-3" />
                                {{ timeAgo(comment.created_at) }}
                            </span>
                        </div>

                        <div v-if="comment.user_id === currentUserId">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-6 w-6 opacity-0 transition-opacity group-hover:opacity-100"
                                    >
                                        <MoreVertical
                                            class="h-3 w-3 text-slate-400"
                                        />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-32">
                                    <DropdownMenuItem
                                        @click="deleteComment(comment.id)"
                                        class="text-red-600 focus:text-red-600"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                    <div
                        class="rounded-2xl rounded-tl-none border border-slate-100 bg-white px-4 py-3 text-[15px] leading-relaxed text-slate-900 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300"
                        v-html="sanitize(comment.body)"
                    ></div>
                </div>
            </div>
        </div>

        <div
            v-if="!readOnly"
            class="shrink-0 border-t border-slate-100 bg-white px-0.5 pt-4 pb-0.5 dark:border-slate-800 dark:bg-slate-900"
            @keydown.meta.enter="submitComment"
            @keydown.ctrl.enter="submitComment"
        >
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
                        class="mx-1 h-4 w-px bg-slate-200 dark:bg-slate-700"
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
                    <div
                        class="mx-1 h-4 w-px bg-slate-200 dark:bg-slate-700"
                    ></div>
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
            <div class="mt-2 flex items-center justify-between px-1 pb-2">
                <span class="text-[10px] text-slate-400"
                    >Cmd + Enter to post</span
                >
                <Button
                    @click="submitComment"
                    :disabled="!form.body.trim() || form.processing || isUploading"
                    size="sm"
                    class="h-8 rounded-lg bg-projector-primary-600 px-4 text-[10px] font-bold tracking-widest uppercase hover:bg-projector-primary-700"
                >
                    <Loader2
                        v-if="form.processing || isUploading"
                        class="mr-1 h-3 w-3 animate-spin"
                    />
                    <Send v-else class="mr-1 h-3 w-3" />
                    {{ isUploading ? 'Uploading…' : 'Post' }}
                </Button>
            </div>
        </div>
    </div>
</template>
