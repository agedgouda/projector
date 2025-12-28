<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { ChevronLeft } from 'lucide-vue-next'; // Optional: for a back button icon

const props = defineProps<{
    project: any;
}>();

// Breadcrumbs help the user navigate back to the specific client
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Clients', href: '/clients' },
    { title: props.project.client.company_name, href: `/clients/${props.project.client_id}` },
    { title: props.project.name, href: '#' },
];
</script>

<template>
    <Head :title="project.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-7xl mx-auto w-full">
            <div class="mb-6">
                <Link
                    :href="`/clients/${project.client_id}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-indigo-600 transition-colors mb-4"
                >
                    <ChevronLeft class="w-4 h-4 mr-1" />
                    Back to Client
                </Link>

                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white leading-tight">
                            {{ project.name }}
                        </h1>
                        <p class="text-gray-500 dark:text-gray-400">
                            Client: <span class="font-medium text-gray-700 dark:text-gray-200">{{ project.client.company_name }}</span>
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 border border-green-200 dark:border-green-800">
                            {{ project.status || 'Active' }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-3 space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
                            <h2 class="font-bold text-gray-900 dark:text-white text-lg">Description</h2>

                            <Button variant="secondary" size="sm">
                                Edit Details
                            </Button>
                        </div>

                        <div class="p-6">
                            <p class="text-gray-600 dark:text-gray-300 leading-relaxed whitespace-pre-wrap">
                                {{ project.description || 'No description provided for this project.' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
