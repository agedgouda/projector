<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MobileLayout from '@/layouts/MobileLayout.vue';
import { ChevronRight, FileText, RefreshCw, Mic } from 'lucide-vue-next';
import mobileRoutes from '@/routes/mobile';
import mobileNoteRoutes from '@/routes/mobile/notes';
import mobileRecordRoutes from '@/routes/mobile/record';
import { formatProjectLabel } from '@/lib/utils';

const props = defineProps<{
    project: { id: string; name: string; client_name: string | null };
    notes: Array<{ id: string; name: string; status: 'processing' | 'processed'; created_at: string | null }>;
}>();

const title = computed(() => formatProjectLabel(props.project));

const formatDate = (value: string | null) => {
    if (!value) { return ''; }
    return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
};
</script>

<template>
    <Head :title="title" />

    <MobileLayout :title="title" :back-href="mobileRoutes.dashboard().url">
        <template #actions>
            <Link
                :href="mobileRecordRoutes.show(project.id).url"
                class="h-11 w-11 flex items-center justify-center text-slate-500 dark:text-slate-400 active:opacity-60"
                aria-label="Record a new meeting for this project"
            >
                <Mic class="w-5 h-5" />
            </Link>
        </template>

        <div class="p-4 space-y-3">
            <div
                v-if="notes.length === 0"
                class="flex flex-col items-center justify-center py-20 text-center"
            >
                <FileText class="w-10 h-10 text-slate-300 dark:text-slate-700 mb-3" />
                <p class="text-sm font-bold text-slate-500">No notes yet</p>
            </div>

            <Link
                v-for="note in notes"
                :key="note.id"
                :href="mobileNoteRoutes.show({ project: project.id, document: note.id }).url"
                class="flex items-center justify-between gap-3 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-4 active:bg-slate-50 dark:active:bg-white/5"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-bold text-slate-900 dark:text-white truncate">{{ note.name }}</p>
                    <div class="flex items-center gap-1.5 mt-1">
                        <RefreshCw v-if="note.status === 'processing'" class="w-3 h-3 text-amber-500 animate-spin" />
                        <span
                            class="text-[11px] font-black uppercase tracking-wider"
                            :class="note.status === 'processing' ? 'text-amber-500' : 'text-slate-400'"
                        >
                            {{ note.status === 'processing' ? 'Processing' : formatDate(note.created_at) }}
                        </span>
                    </div>
                </div>
                <ChevronRight class="w-5 h-5 text-slate-300 dark:text-slate-600 shrink-0" />
            </Link>
        </div>
    </MobileLayout>
</template>
