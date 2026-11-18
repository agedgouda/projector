<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import organizationRoutes from '@/routes/organizations/index';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import OrganizationForm from './Partials/OrganizationForm.vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Organizations', href: organizationRoutes.index.url() },
    { title: 'New Organization', href: '' },
];
</script>

<template>
    <Head title="Create Organization" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-8 p-6">
            <div class="mb-10">
                <h1
                    class="text-3xl font-black tracking-tighter text-gray-900 uppercase dark:text-white"
                >
                    New Organization
                </h1>
                <p class="mt-1 text-sm font-medium text-gray-500">
                    Setup a workspace to manage your clients and projects.
                </p>
            </div>

            <OrganizationForm
                @cancel="router.visit(organizationRoutes.index.url())"
                @success="router.visit(organizationRoutes.index.url())"
            />
        </div>
    </AppLayout>
</template>
