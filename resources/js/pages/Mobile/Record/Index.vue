<script setup lang="ts">
import MobileLayout from '@/layouts/MobileLayout.vue';
import { formatProjectLabel } from '@/lib/utils';
import mobileRoutes from '@/routes/mobile';
import mobileRecordRoutes from '@/routes/mobile/record';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, FolderKanban } from '@lucide/vue';

defineProps<{
    projects: Array<{ id: string; name: string; client_name: string | null }>;
}>();
</script>

<template>
    <Head title="Record a Meeting" />

    <MobileLayout
        title="Record a Meeting"
        :back-href="mobileRoutes.dashboard().url"
    >
        <div class="space-y-3 p-4">
            <p class="px-1 pb-1 text-[13px] text-slate-400">
                Choose which project this recording belongs to.
            </p>

            <div
                v-if="projects.length === 0"
                class="flex flex-col items-center justify-center py-20 text-center"
            >
                <FolderKanban
                    class="mb-3 h-10 w-10 text-slate-300 dark:text-slate-700"
                />
                <p class="text-sm font-bold text-slate-500">No projects yet</p>
            </div>

            <Link
                v-for="project in projects"
                :key="project.id"
                :href="mobileRecordRoutes.show(project.id).url"
                class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 active:bg-slate-50 dark:border-white/10 dark:bg-slate-900 dark:active:bg-white/5"
            >
                <div class="min-w-0">
                    <p
                        class="truncate font-bold text-slate-900 dark:text-white"
                    >
                        {{ formatProjectLabel(project) }}
                    </p>
                </div>
                <ChevronRight
                    class="h-5 w-5 shrink-0 text-slate-300 dark:text-slate-600"
                />
            </Link>
        </div>
    </MobileLayout>
</template>
