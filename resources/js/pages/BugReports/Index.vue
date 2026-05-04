<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { type BreadcrumbItem } from '@/types';
import bugReportsRoutes from '@/routes/bug-reports/index';

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Bug Reports', href: '' },
];

const toggleStatus = (report: BugReport) => {
    router.patch(bugReportsRoutes.update({ bugReport: report.id }).url, {}, {
        onSuccess: () => toast.success(report.status === 'open' ? 'Marked as resolved.' : 'Reopened.'),
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Bug Reports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full px-6 py-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-black tracking-tight text-slate-900 dark:text-white">Bug Reports</h1>
                    <p class="text-sm text-slate-500 mt-0.5">{{ bugReports.length }} total report{{ bugReports.length !== 1 ? 's' : '' }}</p>
                </div>
            </div>

            <div v-if="bugReports.length === 0" class="bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl p-12 text-center shadow-sm">
                <p class="text-sm text-slate-500">No bug reports yet.</p>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="report in bugReports"
                    :key="report.id"
                    class="bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl p-6 shadow-sm space-y-3"
                    :class="{ 'opacity-60': report.status === 'resolved' }"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <Badge :variant="report.status === 'open' ? 'destructive' : 'secondary'" class="text-[10px] font-black uppercase tracking-widest shrink-0">
                                    {{ report.status }}
                                </Badge>
                                <h2 class="text-sm font-bold text-slate-900 dark:text-white truncate">{{ report.title }}</h2>
                            </div>
                            <p class="text-[11px] text-slate-400">
                                {{ report.reporter }} &middot; {{ report.reporter_email }} &middot; {{ report.created_at }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            class="shrink-0 text-[10px] font-black uppercase tracking-widest"
                            @click="toggleStatus(report)"
                        >
                            {{ report.status === 'open' ? 'Mark Resolved' : 'Reopen' }}
                        </Button>
                    </div>

                    <p class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">{{ report.description }}</p>

                    <a
                        v-if="report.page_url"
                        :href="report.page_url"
                        class="inline-block text-[11px] text-indigo-500 hover:underline truncate max-w-full"
                    >
                        {{ report.page_url }}
                    </a>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
