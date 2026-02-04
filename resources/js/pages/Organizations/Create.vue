<script setup lang="ts">
import OrganizationForm from './Partials/OrganizationForm.vue';
import { Head, router } from '@inertiajs/vue3';
import organizationRoutes from '@/routes/organizations/index';
</script>

<template>
    <Head title="Create Organization" />

    <div class="max-w-2xl mx-auto py-12 px-4">
        <div class="mb-10">
            <h1 class="text-3xl font-black uppercase tracking-tighter text-gray-900 dark:text-white">
                New Organization
            </h1>
            <p class="text-sm text-gray-500 font-medium mt-1">
                Setup a workspace to manage your clients and protocols.
            </p>
        </div>

        <div class="bg-white dark:bg-gray-900/50 p-1 border border-gray-100 dark:border-gray-800 rounded-2xl shadow-sm">
            <div class="p-6">
                <OrganizationForm
                    @cancel="router.visit(organizationRoutes.index.url())"
                    @success="router.visit(organizationRoutes.index.url())"
                />
            </div>
        </div>
    </div>
</template>
