<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import MobileLayout from '@/layouts/MobileLayout.vue';
import { ChevronRight } from 'lucide-vue-next';
import mobileProjectRoutes from '@/routes/mobile/projects';
import mobileDocumentRoutes from '@/routes/mobile/documents';
import { kanbanDotClasses, priorityDotClasses } from '@/lib/constants';

interface IndexItem {
    id: string;
    name: string;
    typeLabel: string;
    isTask: boolean;
    priority: string | null;
    taskStatus: string | null;
    assignee: { id: number; name: string } | null;
    depth: number;
}

defineProps<{
    project: { id: string; name: string };
    note: { id: string; name: string };
    items: IndexItem[];
}>();
</script>

<template>
    <Head :title="note.name" />

    <MobileLayout :title="note.name" :back-href="mobileProjectRoutes.show(project.id).url">
        <div class="p-4 space-y-2">
            <Link
                v-for="item in items"
                :key="item.id"
                :href="mobileDocumentRoutes.show({ project: project.id, document: item.id }).url"
                class="flex items-center justify-between gap-3 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-4 active:bg-slate-50 dark:active:bg-white/5"
                :style="{ marginLeft: `${item.depth * 0.75}rem` }"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-[9px] font-black uppercase tracking-wider text-slate-400">{{ item.typeLabel }}</span>
                        <template v-if="item.isTask">
                            <div :class="[kanbanDotClasses.slate, 'w-1.5 h-1.5 rounded-full shrink-0']"></div>
                            <div :class="[priorityDotClasses[item.priority ?? 'low'] ?? priorityDotClasses.low, 'w-1.5 h-1.5 rounded-full shrink-0']"></div>
                        </template>
                    </div>
                    <p class="font-bold text-slate-900 dark:text-white truncate">{{ item.name }}</p>
                    <p v-if="item.isTask && item.assignee" class="text-[11px] text-slate-400 mt-0.5 truncate">{{ item.assignee.name }}</p>
                </div>
                <ChevronRight class="w-5 h-5 text-slate-300 dark:text-slate-600 shrink-0" />
            </Link>
        </div>
    </MobileLayout>
</template>
