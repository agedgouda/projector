<script setup lang="ts">
import MobileLayout from '@/layouts/MobileLayout.vue';
import {
    PRIORITY_LABELS,
    kanbanDotClasses,
    priorityDotClasses,
} from '@/lib/constants';
import { formatDateMdy } from '@/lib/utils';
import mobileNoteRoutes from '@/routes/mobile/notes';
import { Head } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import { Calendar, RefreshCw, User as UserIcon } from 'lucide-vue-next';

interface DocumentItem {
    id: string;
    name: string;
    content: string | null;
    typeLabel: string;
    isTask: boolean;
    priority: string | null;
    taskStatus: string | null;
    dueAt: string | null;
    assignee: { id: number; name: string } | null;
}

defineProps<{
    project: { id: string; name: string };
    noteId: string;
    document: DocumentItem & { status: 'processing' | 'processed' };
}>();

const sanitize = (html: string | null) => DOMPurify.sanitize(html ?? '');

const formatDate = (value: string | null) => {
    if (!value) {
        return null;
    }
    return formatDateMdy(value);
};
</script>

<template>
    <Head :title="document.name" />

    <MobileLayout
        :title="document.name"
        :back-href="
            mobileNoteRoutes.show({ project: project.id, document: noteId }).url
        "
    >
        <div class="space-y-6 p-4">
            <div
                v-if="document.status === 'processing'"
                class="flex items-center gap-2 rounded-2xl bg-amber-50 p-4 text-sm font-bold text-amber-700 dark:bg-amber-950/40 dark:text-amber-400"
            >
                <RefreshCw class="h-4 w-4 shrink-0 animate-spin" />
                Still processing…
            </div>

            <div
                class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-slate-900"
            >
                <div class="flex items-center justify-between">
                    <span
                        class="rounded border border-projector-primary-100 bg-projector-primary-50 px-2 py-1 text-[10px] font-black tracking-widest text-projector-primary-600 uppercase dark:border-projector-primary-900 dark:bg-projector-primary-950/30 dark:text-projector-primary-400"
                    >
                        {{ document.typeLabel }}
                    </span>
                </div>

                <div
                    v-if="document.isTask"
                    class="grid grid-cols-2 gap-x-6 gap-y-3 text-[13px]"
                >
                    <div class="flex items-center gap-1.5 text-slate-400">
                        <UserIcon class="h-3.5 w-3.5" />
                        <span
                            class="text-[10px] font-bold tracking-wide uppercase"
                            >Assignee</span
                        >
                    </div>
                    <div
                        class="truncate text-right font-bold text-slate-700 dark:text-slate-300"
                    >
                        {{ document.assignee?.name ?? 'Unassigned' }}
                    </div>

                    <div class="flex items-center gap-1.5 text-slate-400">
                        <Calendar class="h-3.5 w-3.5" />
                        <span
                            class="text-[10px] font-bold tracking-wide uppercase"
                            >Due Date</span
                        >
                    </div>
                    <div
                        class="text-right font-bold text-slate-700 dark:text-slate-300"
                    >
                        {{ formatDate(document.dueAt) ?? '—' }}
                    </div>

                    <div class="flex items-center gap-1.5 text-slate-400">
                        <span
                            class="text-[10px] font-bold tracking-wide uppercase"
                            >Status</span
                        >
                    </div>
                    <div class="flex items-center justify-end gap-1.5">
                        <span
                            class="font-bold text-slate-700 dark:text-slate-300"
                            >{{ document.taskStatus ?? 'todo' }}</span
                        >
                        <div
                            :class="[
                                kanbanDotClasses.slate,
                                'h-2 w-2 rounded-full',
                            ]"
                        ></div>
                    </div>

                    <div class="flex items-center gap-1.5 text-slate-400">
                        <span
                            class="text-[10px] font-bold tracking-wide uppercase"
                            >Priority</span
                        >
                    </div>
                    <div class="flex items-center justify-end gap-1.5">
                        <span
                            class="font-bold text-slate-700 dark:text-slate-300"
                            >{{
                                PRIORITY_LABELS[document.priority ?? 'low'] ??
                                document.priority
                            }}</span
                        >
                        <div
                            :class="[
                                priorityDotClasses[
                                    document.priority ?? 'low'
                                ] ?? priorityDotClasses.low,
                                'h-2 w-2 rounded-full',
                            ]"
                        ></div>
                    </div>
                </div>

                <div
                    class="note-content text-[15px] leading-relaxed text-slate-900 dark:text-slate-300"
                    v-html="sanitize(document.content) || 'No content yet.'"
                ></div>
            </div>
        </div>
    </MobileLayout>
</template>

<style scoped>
.note-content :deep(p) {
    margin-top: 0.75rem;
    margin-bottom: 0.75rem;
}
.note-content :deep(h1),
.note-content :deep(h2),
.note-content :deep(h3) {
    font-weight: 800;
    margin-top: 1.25rem;
    margin-bottom: 0.5rem;
}
.note-content :deep(ol) {
    list-style-type: decimal;
    padding-left: 1.5rem;
}
.note-content :deep(ul) {
    list-style-type: disc;
    padding-left: 1.5rem;
}
</style>
