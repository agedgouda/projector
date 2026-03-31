<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import OrganizationForm from './Partials/OrganizationForm.vue';
import { Head, router } from '@inertiajs/vue3';
import organizationRoutes from '@/routes/organizations/index';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Organizations', href: organizationRoutes.index.url() },
    { title: 'New Organization', href: '' }
];

</script>

<template>
    <Head title="Create Organization" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full space-y-8">
            <div class="mb-10">
                <h1 class="text-3xl font-black uppercase tracking-tighter text-gray-900 dark:text-white">
                    New Organization
                </h1>
                <p class="text-sm text-gray-500 font-medium mt-1">
                    Setup a workspace to manage your clients and protocols.
                </p>
            </div>

            <OrganizationForm
                @cancel="router.visit(organizationRoutes.index.url())"
                @success="router.visit(organizationRoutes.index.url())"
            />

        </div>
    </AppLayout>
</template>
