<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ClientList from '@/components/clients/ClientList.vue';
import clientRoutes from '@/routes/clients/index';
import { Head } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Building2 } from 'lucide-vue-next';

const props = defineProps<{
    clients: Client[];
    projectTypes: ProjectType[];
    activeOrg: Organization;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clients', href: clientRoutes.index.url() }];
</script>

<template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full">
            <div class="mb-8">
                <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white uppercase flex items-center gap-3">
                    <Building2 class="w-8 h-8 text-indigo-500" />
                    {{ activeOrg.name }} Client Management
                </h1>
                <p class="text-sm text-gray-500 mt-1">Manage client relationships and project history.</p>
            </div>

            <ClientList :clients="props.clients" :project-types="props.projectTypes" />
        </div>
    </AppLayout>
</template>
