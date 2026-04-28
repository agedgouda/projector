<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';
import { type BreadcrumbItem } from '@/types';
import projectRoutes from '@/routes/projects/index';
import statusMeetingsRoutes from '@/routes/status-meetings/index';

interface PreselectedClient {
    id: string;
    company_name: string;
}

const props = defineProps<{
    clients: Client[];
    projectTypes: ProjectType[];
    initialName: string;
    preselectedClient: PreselectedClient | null;
    backUrl: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: projectRoutes.index.url() },
    { title: 'New Project', href: '' },
];

const goBack = () => {
    if (props.backUrl) {
        router.visit(props.backUrl);
    } else {
        router.visit(projectRoutes.index.url());
    }
};

const handleSuccess = () => {
    if (props.backUrl) {
        router.visit(props.backUrl);
    } else {
        router.visit(projectRoutes.index.url());
    }
};
</script>

<template>
    <Head title="New Project" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full">
            <!-- Header -->
            <div class="mb-6">
                <button
                    @click="goBack"
                    class="flex items-center gap-2 text-sm text-slate-500 hover:text-indigo-600 transition-colors mb-6"
                >
                    <ArrowLeft class="w-3 h-3" />
                    {{ backUrl ? 'Back' : 'Projects' }}
                </button>

                <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">New Project</h1>
                <p v-if="initialName" class="text-sm text-gray-500 mt-1">
                    Creating project for <strong>{{ initialName }}</strong>
                </p>
            </div>

            <div class="max-w-xl bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl p-8 shadow-sm">
                <ProjectEntryForm
                    :clients="clients"
                    :client="preselectedClient as any"
                    :project-types="projectTypes"
                    :initial-name="initialName"
                    @success="handleSuccess"
                    @cancel="goBack"
                />
            </div>
        </div>
    </AppLayout>
</template>
