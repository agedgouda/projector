<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import orgDocumentsRoutes from '@/routes/organizations/documents/index';
import statusMeetingsRoutes from '@/routes/status-meetings/index';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    organization: { id: string; name: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Status Meetings', href: statusMeetingsRoutes.index.url() },
    { title: props.organization.name, href: statusMeetingsRoutes.index.url({ query: { org: props.organization.id } }) },
    { title: 'New Status Meeting', href: '' },
];

const form = useForm({
    name: '',
    type: 'org_intake',
    content: '',
    metadata: {},
});

const handleSubmit = () => {
    form.post(orgDocumentsRoutes.store({ organization: props.organization.id }).url, {
        onSuccess: () => toast.success('Org intake created.'),
        onError: () => toast.error('Please correct the errors.'),
    });
};
</script>

<template>
    <Head title="New Status Meeting" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full px-6 py-6">
            <div class="grid grid-cols-12 gap-12">
                <div class="col-span-12 lg:col-span-8 space-y-6">
                    <div class="bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl p-8 space-y-6 shadow-sm">
                        <div class="space-y-1">
                            <h1 class="text-xl font-black tracking-tight text-slate-900 dark:text-white">New Status Meeting</h1>
                            <p class="text-sm text-slate-500">Capture notes from a status meeting that spans multiple projects. The AI will route action items to the appropriate projects.</p>
                        </div>

                        <form @submit.prevent="handleSubmit" class="space-y-5">
                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Title</label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    placeholder="e.g. Q2 Strategy Review"
                                    class="w-full rounded-xl border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 px-4 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-projector-primary-500"
                                />
                                <p v-if="form.errors.name" class="text-xs text-red-500">{{ form.errors.name }}</p>
                            </div>

                            <div class="space-y-1.5">
                                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Notes / Transcript</label>
                                <textarea
                                    v-model="form.content"
                                    rows="16"
                                    placeholder="Paste your meeting notes or transcript here..."
                                    class="w-full rounded-xl border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 px-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-projector-primary-500 resize-y font-mono leading-relaxed"
                                />
                                <p v-if="form.errors.content" class="text-xs text-red-500">{{ form.errors.content }}</p>
                            </div>

                            <div class="flex justify-end gap-3 pt-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="router.visit(statusMeetingsRoutes.index.url())"
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="bg-projector-primary-600 hover:bg-projector-primary-700 text-white font-bold px-6 rounded-xl"
                                >
                                    {{ form.processing ? 'Saving...' : 'Save' }}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
