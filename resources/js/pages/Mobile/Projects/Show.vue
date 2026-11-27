<script setup lang="ts">
import MobileLayout from '@/layouts/MobileLayout.vue';
import { formatProjectLabel, formatTimestampMdy } from '@/lib/utils';
import mobileRoutes from '@/routes/mobile';
import mobileNoteRoutes from '@/routes/mobile/notes';
import mobileRecordRoutes from '@/routes/mobile/record';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, FileText, Mic, RefreshCw } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    project: { id: string; name: string; client_name: string | null };
    notes: Array<{
        id: string;
        name: string;
        status: 'processing' | 'processed';
        created_at: string | null;
    }>;
}>();

const title = computed(() => formatProjectLabel(props.project));

const formatDate = (value: string | null) => {
    if (!value) {
        return '';
    }
    return formatTimestampMdy(value);
};
</script>

<template>
    <Head :title="title" />

    <MobileLayout :title="title" :back-href="mobileRoutes.dashboard().url">
        <template #actions>
            <Link
                :href="mobileRecordRoutes.show(project.id).url"
                class="flex h-11 w-11 items-center justify-center text-slate-500 active:opacity-60 dark:text-slate-400"
                aria-label="Record a new meeting for this project"
            >
                <Mic class="h-5 w-5" />
            </Link>
        </template>

        <div class="space-y-3 p-4">
            <div
                v-if="notes.length === 0"
                class="flex flex-col items-center justify-center py-20 text-center"
            >
                <FileText
                    class="mb-3 h-10 w-10 text-slate-300 dark:text-slate-700"
                />
                <p class="text-sm font-bold text-slate-500">No notes yet</p>
            </div>

            <Link
                v-for="note in notes"
                :key="note.id"
                :href="
                    mobileNoteRoutes.show({
                        project: project.id,
                        document: note.id,
                    }).url
                "
                class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 active:bg-slate-50 dark:border-white/10 dark:bg-slate-900 dark:active:bg-white/5"
            >
                <div class="min-w-0 flex-1">
                    <p
                        class="truncate font-bold text-slate-900 dark:text-white"
                    >
                        {{ note.name }}
                    </p>
                    <div class="mt-1 flex items-center gap-1.5">
                        <RefreshCw
                            v-if="note.status === 'processing'"
                            class="h-3 w-3 animate-spin text-amber-500"
                        />
                        <span
                            class="text-[11px] font-black tracking-wider uppercase"
                            :class="
                                note.status === 'processing'
                                    ? 'text-amber-500'
                                    : 'text-slate-400'
                            "
                        >
                            {{
                                note.status === 'processing'
                                    ? 'Processing'
                                    : formatDate(note.created_at)
                            }}
                        </span>
                    </div>
                </div>
                <ChevronRight
                    class="h-5 w-5 shrink-0 text-slate-300 dark:text-slate-600"
                />
            </Link>
        </div>
    </MobileLayout>
</template>
