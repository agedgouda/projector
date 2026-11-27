<script setup lang="ts">
import MobileLayout from '@/layouts/MobileLayout.vue';
import { kanbanDotClasses, priorityDotClasses } from '@/lib/constants';
import mobileDocumentRoutes from '@/routes/mobile/documents';
import mobileProjectRoutes from '@/routes/mobile/projects';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';

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

    <MobileLayout
        :title="note.name"
        :back-href="mobileProjectRoutes.show(project.id).url"
    >
        <div class="space-y-2 p-4">
            <Link
                v-for="item in items"
                :key="item.id"
                :href="
                    mobileDocumentRoutes.show({
                        project: project.id,
                        document: item.id,
                    }).url
                "
                class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 active:bg-slate-50 dark:border-white/10 dark:bg-slate-900 dark:active:bg-white/5"
                :style="{ marginLeft: `${item.depth * 0.75}rem` }"
            >
                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex items-center gap-2">
                        <span
                            class="text-[9px] font-black tracking-wider text-slate-400 uppercase"
                            >{{ item.typeLabel }}</span
                        >
                        <template v-if="item.isTask">
                            <div
                                :class="[
                                    kanbanDotClasses.slate,
                                    'h-1.5 w-1.5 shrink-0 rounded-full',
                                ]"
                            ></div>
                            <div
                                :class="[
                                    priorityDotClasses[
                                        item.priority ?? 'low'
                                    ] ?? priorityDotClasses.low,
                                    'h-1.5 w-1.5 shrink-0 rounded-full',
                                ]"
                            ></div>
                        </template>
                    </div>
                    <p
                        class="truncate font-bold text-slate-900 dark:text-white"
                    >
                        {{ item.name }}
                    </p>
                    <p
                        v-if="item.isTask && item.assignee"
                        class="mt-0.5 truncate text-[11px] text-slate-400"
                    >
                        {{ item.assignee.name }}
                    </p>
                </div>
                <ChevronRight
                    class="h-5 w-5 shrink-0 text-slate-300 dark:text-slate-600"
                />
            </Link>
        </div>
    </MobileLayout>
</template>
