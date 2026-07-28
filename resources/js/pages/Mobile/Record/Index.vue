<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import MobileLayout from '@/layouts/MobileLayout.vue';
import { ChevronRight, FolderKanban } from 'lucide-vue-next';
import mobileRoutes from '@/routes/mobile';
import mobileRecordRoutes from '@/routes/mobile/record';
import { formatProjectLabel } from '@/lib/utils';

defineProps<{
    projects: Array<{ id: string; name: string; client_name: string | null }>;
}>();
</script>

<template>
    <Head title="Record a Meeting" />

    <MobileLayout title="Record a Meeting" :back-href="mobileRoutes.dashboard().url">
        <div class="p-4 space-y-3">
            <p class="text-[13px] text-slate-400 px-1 pb-1">Choose which project this recording belongs to.</p>

            <div
                v-if="projects.length === 0"
                class="flex flex-col items-center justify-center py-20 text-center"
            >
                <FolderKanban class="w-10 h-10 text-slate-300 dark:text-slate-700 mb-3" />
                <p class="text-sm font-bold text-slate-500">No projects yet</p>
            </div>

            <Link
                v-for="project in projects"
                :key="project.id"
                :href="mobileRecordRoutes.show(project.id).url"
                class="flex items-center justify-between gap-3 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-4 active:bg-slate-50 dark:active:bg-white/5"
            >
                <div class="min-w-0">
                    <p class="font-bold text-slate-900 dark:text-white truncate">{{ formatProjectLabel(project) }}</p>
                </div>
                <ChevronRight class="w-5 h-5 text-slate-300 dark:text-slate-600 shrink-0" />
            </Link>
        </div>
    </MobileLayout>
</template>
