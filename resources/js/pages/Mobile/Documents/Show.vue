<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import MobileLayout from '@/layouts/MobileLayout.vue';
import DOMPurify from 'dompurify';
import { RefreshCw, User as UserIcon, Calendar } from 'lucide-vue-next';
import mobileNoteRoutes from '@/routes/mobile/notes';
import { STATUS_LABELS, PRIORITY_LABELS, statusDotClasses, priorityDotClasses } from '@/lib/constants';

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
    if (!value) { return null; }
    return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};
</script>

<template>
    <Head :title="document.name" />

    <MobileLayout :title="document.name" :back-href="mobileNoteRoutes.show({ project: project.id, document: noteId }).url">
        <div class="p-4 space-y-6">
            <div
                v-if="document.status === 'processing'"
                class="flex items-center gap-2 bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 rounded-2xl p-4 text-sm font-bold"
            >
                <RefreshCw class="w-4 h-4 animate-spin shrink-0" />
                Still processing…
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-black uppercase tracking-widest text-projector-primary-600 bg-projector-primary-50 dark:bg-projector-primary-950/30 dark:text-projector-primary-400 px-2 py-1 rounded border border-projector-primary-100 dark:border-projector-primary-900">
                        {{ document.typeLabel }}
                    </span>
                </div>

                <div v-if="document.isTask" class="grid grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
                    <div class="flex items-center gap-1.5 text-slate-400">
                        <UserIcon class="w-3.5 h-3.5" />
                        <span class="font-bold uppercase tracking-wide text-[10px]">Assignee</span>
                    </div>
                    <div class="text-right font-bold text-slate-700 dark:text-slate-300 truncate">
                        {{ document.assignee?.name ?? 'Unassigned' }}
                    </div>

                    <div class="flex items-center gap-1.5 text-slate-400">
                        <Calendar class="w-3.5 h-3.5" />
                        <span class="font-bold uppercase tracking-wide text-[10px]">Due Date</span>
                    </div>
                    <div class="text-right font-bold text-slate-700 dark:text-slate-300">
                        {{ formatDate(document.dueAt) ?? '—' }}
                    </div>

                    <div class="flex items-center gap-1.5 text-slate-400">
                        <span class="font-bold uppercase tracking-wide text-[10px]">Status</span>
                    </div>
                    <div class="flex items-center justify-end gap-1.5">
                        <span class="font-bold text-slate-700 dark:text-slate-300">{{ STATUS_LABELS[document.taskStatus ?? 'todo'] }}</span>
                        <div :class="[statusDotClasses[document.taskStatus ?? 'todo'], 'w-2 h-2 rounded-full']"></div>
                    </div>

                    <div class="flex items-center gap-1.5 text-slate-400">
                        <span class="font-bold uppercase tracking-wide text-[10px]">Priority</span>
                    </div>
                    <div class="flex items-center justify-end gap-1.5">
                        <span class="font-bold text-slate-700 dark:text-slate-300">{{ PRIORITY_LABELS[document.priority ?? 'low'] ?? document.priority }}</span>
                        <div :class="[priorityDotClasses[document.priority ?? 'low'] ?? priorityDotClasses.low, 'w-2 h-2 rounded-full']"></div>
                    </div>
                </div>

                <div class="note-content text-[15px] text-slate-900 dark:text-slate-300 leading-relaxed" v-html="sanitize(document.content) || 'No content yet.'"></div>
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
