<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Send, MessageSquare, Clock, Loader2, MoreVertical, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { ref, watch, nextTick, onMounted } from 'vue';
import CommentRoutes from '@/routes/comments/index';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger
} from '@/components/ui/dropdown-menu';
import { toast } from 'vue-sonner';

const props = defineProps<{
    comments: Comment[];
    commentableType: 'task' | 'document';
    commentableId: string | number;
}>();

// Typing the page props solves the 'unknown' error
const page = usePage<AppPageProps>();
const currentUserId = page.props.auth.user.id;

const scrollContainer = ref<HTMLElement | null>(null);

const form = useForm({
    body: '',
    type: props.commentableType,
    id: props.commentableId,
});

const scrollToBottom = () => {
    if (scrollContainer.value) {
        scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
    }
};

onMounted(() => scrollToBottom());

watch(() => props.comments.length, () => {
    nextTick(() => scrollToBottom());
});

watch(() => props.commentableId, (newId) => {
    form.id = newId;
});

const submitComment = () => {
    if (!form.body.trim() || form.processing) return;
    form.post(CommentRoutes.store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('body'),
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
    const seconds = Math.floor((new Date().getTime() - new Date(date).getTime()) / 1000);
    if (seconds < 5) return 'just now';
    if (seconds < 60) return `${seconds}s ago`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    return new Date(date).toLocaleDateString();
};
</script>

<template>
    <div class="flex flex-col h-full overflow-hidden">
        <div
            ref="scrollContainer"
            class="flex-1 overflow-y-auto space-y-6 pb-6 custom-scrollbar"
        >
            <div v-if="comments.length === 0" class="py-12 text-center border-2 border-dashed border-slate-100 dark:border-slate-800 rounded-3xl mt-4">
                <MessageSquare class="w-8 h-8 mx-auto mb-3 text-slate-300" />
                <p class="text-xs font-medium text-slate-400 uppercase tracking-widest">No discussion yet</p>
            </div>

            <div v-for="comment in comments" :key="comment.id" class="group flex gap-3 pr-2">
                <div class="h-8 w-8 rounded-full bg-indigo-50 dark:bg-indigo-950 border border-indigo-100 dark:border-indigo-900 flex items-center justify-center text-[10px] font-black text-indigo-600 dark:text-indigo-400 shrink-0">
                    {{ comment.user?.first_name?.[0] }}{{ comment.user?.last_name?.[0] }}
                </div>

                <div class="flex flex-col gap-1 min-w-0 flex-1">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100">{{ comment.user?.name }}</span>
                            <span class="text-[10px] text-slate-400 flex items-center gap-1">
                                <Clock class="w-3 h-3" />
                                {{ timeAgo(comment.created_at) }}
                            </span>
                        </div>

                        <div v-if="comment.user_id === currentUserId">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" class="h-6 w-6 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <MoreVertical class="w-3 h-3 text-slate-400" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-32">
                                    <DropdownMenuItem @click="deleteComment(comment.id)" class="text-red-600 focus:text-red-600">
                                        <Trash2 class="w-4 h-4 mr-2" />
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl rounded-tl-none px-4 py-3 text-sm text-slate-600 dark:text-slate-300 leading-relaxed shadow-sm">
                        {{ comment.body }}
                    </div>
                </div>
            </div>
        </div>

        <div class="shrink-0 bg-white dark:bg-slate-900 pt-4 border-t border-slate-100 dark:border-slate-800">
            <div class="relative group">
                <textarea
                    v-model="form.body"
                    rows="3"
                    placeholder="Write a comment..."
                    class="w-full rounded-2xl border-slate-200 dark:border-slate-800 p-4 pr-14 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all bg-slate-50/50 dark:bg-slate-950/50"
                    @keydown.enter.meta="submitComment"
                ></textarea>

                <Button
                    @click="submitComment"
                    :disabled="!form.body.trim() || form.processing"
                    size="icon"
                    class="absolute bottom-3 right-3 h-9 w-9 bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-lg"
                >
                    <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin text-white" />
                    <Send v-else class="w-4 h-4 text-white" />
                </Button>
            </div>
            <div class="flex justify-between items-center mt-2 px-1 pb-2">
                <span class="text-[10px] text-slate-400 uppercase font-bold tracking-tight">Markdown supported</span>
                <span class="text-[10px] text-slate-400">Cmd + Enter to post</span>
            </div>
        </div>
    </div>
</template>
