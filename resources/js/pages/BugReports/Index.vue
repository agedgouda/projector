<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';
import { formatDateMdy } from '@/lib/utils';
import bugReportsRoutes from '@/routes/bug-reports/index';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

interface BugReport {
    id: string;
    title: string;
    description: string;
    page_url: string | null;
    status: 'open' | 'resolved';
    reporter: string;
    reporter_email: string;
    created_at: string;
}

defineProps<{
    bugReports: BugReport[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bug Reports', href: '' }];

const toggleStatus = (report: BugReport) => {
    router.patch(
        bugReportsRoutes.update({ bugReport: report.id }).url,
        {},
        {
            onSuccess: () =>
                toast.success(
                    report.status === 'open'
                        ? 'Marked as resolved.'
                        : 'Reopened.',
                ),
            preserveScroll: true,
        },
    );
};
</script>

<template>
    <Head title="Bug Reports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-4 px-6 py-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-xl font-black tracking-tight text-slate-900 dark:text-white"
                    >
                        Bug Reports
                    </h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        {{ bugReports.length }} total report{{
                            bugReports.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
            </div>

            <div
                v-if="bugReports.length === 0"
                class="rounded-2xl border-2 border-dashed border-gray-200 p-12 text-center dark:border-zinc-800"
            >
                <p class="text-sm text-slate-500">No bug reports yet.</p>
            </div>

            <div v-else class="space-y-1">
                <div
                    v-for="report in bugReports"
                    :key="report.id"
                    :class="[
                        'space-y-3 rounded-md p-4 transition-colors',
                        FLAT_ROW_HOVER,
                        { 'opacity-60': report.status === 'resolved' },
                    ]"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 space-y-1">
                            <div class="flex items-center gap-2">
                                <Badge
                                    :variant="
                                        report.status === 'open'
                                            ? 'destructive'
                                            : 'secondary'
                                    "
                                    class="shrink-0 text-[10px] font-black tracking-widest uppercase"
                                >
                                    {{ report.status }}
                                </Badge>
                                <h2
                                    class="truncate text-sm font-bold text-slate-900 dark:text-white"
                                >
                                    {{ report.title }}
                                </h2>
                            </div>
                            <p class="text-[11px] text-slate-400">
                                {{ report.reporter }} &middot;
                                {{ report.reporter_email }} &middot;
                                {{ formatDateMdy(report.created_at) }}
                                {{ report.created_at.slice(11) }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            class="shrink-0 rounded-md text-[10px] font-black tracking-widest uppercase"
                            @click="toggleStatus(report)"
                        >
                            {{
                                report.status === 'open'
                                    ? 'Mark Resolved'
                                    : 'Reopen'
                            }}
                        </Button>
                    </div>

                    <p
                        class="text-sm leading-relaxed whitespace-pre-wrap text-slate-700 dark:text-slate-300"
                    >
                        {{ report.description }}
                    </p>

                    <a
                        v-if="report.page_url"
                        :href="report.page_url"
                        class="inline-block max-w-full truncate text-[11px] text-projector-primary-500 hover:underline"
                    >
                        {{ report.page_url }}
                    </a>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
