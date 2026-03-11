<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import OrganizationForm from './Partials/OrganizationForm.vue';
import { Head, router } from '@inertiajs/vue3';
import organizationRoutes from '@/routes/organizations/index';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    organization: Organization;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Organizations', href: organizationRoutes.index.url() },
    { title: props.organization.name, href: '' },
];

</script>

<template>
    <Head title="Edit Organization" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-8 max-w-5xl mx-auto w-full space-y-8">
            <div class="mb-10">
                <h1 class="text-3xl font-black uppercase tracking-tighter text-gray-900 dark:text-white">
                    {{ organization.name }}
                </h1>
            </div>

            <OrganizationForm
                :organization="props.organization"
                @cancel="router.visit(organizationRoutes.index.url())"
                @success="router.visit(organizationRoutes.index.url())"
            />

        </div>
    </AppLayout>
</template>
