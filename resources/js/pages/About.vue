<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'About', href: '/about' }];
</script>

<template>
    <Head title="About Projector" />

    <PublicLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full max-w-3xl space-y-8 prose-content">
            <div>
                <h1 class="text-2xl font-black tracking-tighter text-gray-900 dark:text-white">Projector</h1>
                <p class="text-slate-500 dark:text-slate-400 mt-2">
                    A document-first, AI-assisted project and task management application.
                </p>
            </div>

            <section class="space-y-3">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">What Projector does</h2>
                <p>
                    Projector helps teams turn raw meeting notes and transcripts into structured project
                    documentation, action items, and tasks using admin-defined AI pipelines. Humans review and
                    approve every AI-generated document before it becomes a task, and every task stays linked
                    back to the document it came from for full traceability.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Google account connection</h2>
                <p>
                    Projector optionally lets a user connect their own Google account to export task reports
                    directly to Google Sheets and Google Docs, created in that user's own Google Drive. This
                    requests exactly one permission — access limited to files Projector itself creates — never
                    access to a user's existing Drive contents or any other Google service.
                </p>
            </section>

            <section class="space-y-3">
                <p>
                    Full details on what data is collected and how it's used, including the Google
                    integration, are in the
                    <Link href="/privacy" class="text-projector-primary-600 hover:underline">Privacy Policy</Link>.
                </p>
            </section>
        </div>
    </PublicLayout>
</template>
