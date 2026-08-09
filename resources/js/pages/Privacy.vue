<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Privacy Policy', href: '/privacy' }];
</script>

<template>
    <Head title="Privacy Policy" />

    <PublicLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full max-w-3xl space-y-8 prose-content">
            <div>
                <h1 class="text-2xl font-black tracking-tighter text-gray-900 dark:text-white">Privacy Policy</h1>
                <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-2">Last updated August 9, 2026</p>
            </div>

            <section class="space-y-3">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Overview</h2>
                <p>
                    Projector is a project and task management application. This policy explains what data
                    Projector collects, how it's used, and — specifically, since this is required for our use
                    of Google APIs — what happens when you connect a Google account.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Google Account Connection</h2>
                <p>
                    Projector optionally lets you connect your own Google account to export task reports
                    directly to Google Sheets and Google Docs in your own Google Drive.
                </p>
                <p>This feature requests exactly one Google OAuth scope:</p>
                <p class="font-mono text-sm bg-slate-100 dark:bg-slate-900 rounded px-3 py-2 inline-block">
                    https://www.googleapis.com/auth/drive.file
                </p>
                <p>This scope only grants Projector access to files that Projector itself creates. It does not grant:</p>
                <ul class="list-disc pl-6 space-y-1">
                    <li>Access to any existing files already in your Google Drive</li>
                    <li>Access to files created by any other application</li>
                    <li>Any access to Gmail, Calendar, Contacts, or any other Google service</li>
                </ul>
                <p>
                    When you click an export button, Projector uses this connection to create a new Google
                    Sheet or Google Doc containing the task report you requested, and writes that report's
                    data into it. That file is created directly in your own Google Drive — Projector does not
                    retain a copy of the exported file's contents after creating it.
                </p>
                <p>
                    Projector stores your Google access and refresh tokens, encrypted at rest, solely to
                    maintain this connection between export requests. You can revoke this connection at any
                    time from <strong>Settings &gt; Integrations</strong> within Projector, which deletes the
                    stored tokens immediately. You can also revoke access directly from your
                    <a
                        href="https://myaccount.google.com/permissions"
                        target="_blank"
                        rel="noopener"
                        class="text-projector-primary-600 hover:underline"
                    >Google Account permissions page</a>.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Other Data Projector Collects</h2>
                <p>
                    Projector stores the account, organization, project, document, and task data you and your
                    organization create while using the app. Some document content may be processed by a
                    configured AI provider (such as OpenAI, Google Gemini, or a self-hosted Ollama instance) to
                    generate drafts, summaries, or embeddings as part of Projector's core workflow features.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Data Sharing</h2>
                <p>
                    Projector does not sell your data or share it with third parties, other than the AI and
                    infrastructure providers necessary to operate the features described above.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Contact</h2>
                <p>
                    Questions about this policy or your data can be sent to
                    <a href="mailto:jeffkaufman@kaufmaninternational.com" class="text-projector-primary-600 hover:underline">
                        jeffkaufman@kaufmaninternational.com
                    </a>.
                </p>
            </section>
        </div>
    </PublicLayout>
</template>
