<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import bugReportsRoutes from '@/routes/bug-reports/index';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Report a Bug', href: '' },
];

const form = useForm({
    title: '',
    description: '',
    page_url: window.location.href,
});

const handleSubmit = () => {
    form.post(bugReportsRoutes.store().url, {
        onSuccess: () => {
            toast.success('Bug report submitted. Thank you!');
            form.reset();
        },
        onError: () => toast.error('Please correct the errors below.'),
    });
};
</script>

<template>
    <Head title="Report a Bug" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full px-6 py-6">
            <div class="grid grid-cols-12 gap-12">
                <div class="col-span-12 lg:col-span-8 space-y-6">
                    <div class="bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl p-8 space-y-6 shadow-sm">
                        <div class="space-y-1">
                            <h1 class="text-xl font-black tracking-tight text-slate-900 dark:text-white">Report a Bug</h1>
                            <p class="text-sm text-slate-500">Found something that isn't working? Let us know and we'll look into it.</p>
                        </div>

                        <form @submit.prevent="handleSubmit" class="space-y-5">
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Summary</label>
                                <input
                                    v-model="form.title"
                                    type="text"
                                    placeholder="e.g. Clicking Save on the project page shows an error"
                                    class="w-full rounded-xl border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 px-4 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                />
                                <p v-if="form.errors.title" class="text-xs text-red-500">{{ form.errors.title }}</p>
                            </div>

                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">What happened?</label>
                                <textarea
                                    v-model="form.description"
                                    rows="8"
                                    placeholder="Describe what you did, what you expected to happen, and what actually happened..."
                                    class="w-full rounded-xl border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 px-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y leading-relaxed"
                                />
                                <p v-if="form.errors.description" class="text-xs text-red-500">{{ form.errors.description }}</p>
                            </div>

                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Page URL</label>
                                <input
                                    v-model="form.page_url"
                                    type="text"
                                    class="w-full rounded-xl border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 px-4 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                />
                                <p class="text-[11px] text-slate-400">Pre-filled with the page you came from. Update if different.</p>
                            </div>

                            <div class="flex justify-end pt-2">
                                <Button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 rounded-xl"
                                >
                                    {{ form.processing ? 'Submitting...' : 'Submit Bug Report' }}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
