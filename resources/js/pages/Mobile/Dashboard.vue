<script setup lang="ts">
import MobileLayout from '@/layouts/MobileLayout.vue';
import { formatProjectLabel } from '@/lib/utils';
import mobileOrganizationRoutes from '@/routes/mobile/organizations';
import mobileProjectRoutes from '@/routes/mobile/projects';
import mobileRecordRoutes from '@/routes/mobile/record';
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronRight,
    ChevronsUpDown,
    FolderKanban,
    Mic,
} from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    organizationName: string | null;
    canSwitchOrganization: boolean;
    projects: Array<{ id: string; name: string; client_name: string | null }>;
}>();

const title = computed(() =>
    props.organizationName
        ? `Projects for ${props.organizationName}`
        : 'Projects',
);
</script>

<template>
    <Head title="Projects" />

    <MobileLayout :title="title">
        <template #actions>
            <Link
                :href="mobileRecordRoutes.index().url"
                class="flex h-11 w-11 items-center justify-center text-slate-500 active:opacity-60 dark:text-slate-400"
                aria-label="Record a new meeting"
            >
                <Mic class="h-5 w-5" />
            </Link>
        </template>

        <div class="space-y-3 p-4">
            <Link
                v-if="canSwitchOrganization"
                :href="mobileOrganizationRoutes.index().url"
                class="flex items-center gap-1.5 px-1 pb-1 text-[11px] font-black tracking-widest text-slate-400 uppercase"
            >
                Switch Organization
                <ChevronsUpDown class="h-3 w-3" />
            </Link>

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
                :href="mobileProjectRoutes.show(project.id).url"
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
