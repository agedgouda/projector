<script setup lang="ts">
import ProjectEntryForm from '@/components/projects/ProjectEntryForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import projectRoutes from '@/routes/projects/index';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

interface PreselectedClient {
    id: string;
    company_name: string;
}

interface ParentProject {
    id: string;
    name: string;
    client_id: string;
}

const props = defineProps<{
    clients: Client[];
    initialName: string;
    preselectedClient: PreselectedClient | null;
    parentProject: ParentProject | null;
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
        <div class="w-full p-6">
            <!-- Header -->
            <div class="mb-6">
                <button
                    @click="goBack"
                    class="mb-6 flex items-center gap-2 text-sm text-slate-500 transition-colors hover:text-projector-primary-600"
                >
                    <ArrowLeft class="h-3 w-3" />
                    {{ backUrl ? 'Back' : 'Projects' }}
                </button>

                <h1
                    class="text-2xl font-black tracking-tight text-gray-900 dark:text-white"
                >
                    {{ parentProject ? 'New Sub-project' : 'New Project' }}
                </h1>
                <p v-if="parentProject" class="mt-1 text-sm text-gray-500">
                    Creating a sub-project of
                    <strong>{{ parentProject.name }}</strong>
                </p>
                <p v-else-if="initialName" class="mt-1 text-sm text-gray-500">
                    Creating project for <strong>{{ initialName }}</strong>
                </p>
            </div>

            <div
                class="max-w-xl rounded-2xl border border-slate-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
            >
                <ProjectEntryForm
                    :clients="clients"
                    :client="preselectedClient as any"
                    :initial-name="initialName"
                    :parent-project="parentProject"
                    @success="handleSuccess"
                    @cancel="goBack"
                />
            </div>
        </div>
    </AppLayout>
</template>
