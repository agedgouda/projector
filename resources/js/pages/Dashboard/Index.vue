<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Assigned from './Partials/Assigned.vue';
//import dashboard from '@/routes/dashboard';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

defineProps<{
    projectGroups: {
        project: Project;
        documents: ProjectDocument[];
    }[];
    projectTypes:ProjectType[];
    stats: {
        total: number;
        pending: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '#',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">

    <div class="max-w-7xl py-10 px-6">
        <h2 class="text-2xl font-bold mb-3">Your Assignments</h2>
        <div v-for="group in projectGroups" :key="group.project.id" class="mb-8">
           <Assigned
                :documents="group.documents"
                :stats="stats"
                :project="group.project"
                :users="group.project.client.users ?? []"
                :projectTypes="projectTypes"
            />
        </div>
    </div>

    </AppLayout>
</template>
