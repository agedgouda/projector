<script setup lang="ts">
import ClientList from '@/components/clients/ClientList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import clientRoutes from '@/routes/clients/index';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Building2 } from 'lucide-vue-next';

const props = defineProps<{
    clients: Client[];
    activeOrg: Organization;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Clients', href: clientRoutes.index.url() },
];
</script>

<template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full p-6">
            <div class="mb-8">
                <h1
                    class="flex items-center gap-3 text-2xl font-black tracking-tight text-gray-900 uppercase dark:text-white"
                >
                    <Building2 class="h-8 w-8 text-projector-primary-500" />
                    {{ activeOrg.name }} Client Management
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Manage client relationships and project history.
                </p>
            </div>

            <ClientList :clients="props.clients" />
        </div>
    </AppLayout>
</template>
