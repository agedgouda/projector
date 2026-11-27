<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import { RefreshCcw, ShieldAlert } from '@lucide/vue';

defineProps<{
    message: string;
}>();

const refresh = () => router.get('/dashboard');
</script>

<template>
    <AppLayout title="Access Pending">
        <div class="flex min-h-[70vh] flex-col items-center justify-center p-6">
            <div
                class="mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-amber-50"
            >
                <ShieldAlert class="h-10 w-10 text-amber-600" />
            </div>

            <h1 class="mb-2 text-2xl font-black text-gray-900">
                Access Restricted
            </h1>
            <p class="mb-8 max-w-sm text-center text-gray-500">
                {{ message }}
            </p>

            <button
                @click="refresh"
                class="flex items-center gap-2 rounded-2xl bg-gray-900 px-6 py-3 font-bold text-white shadow-lg shadow-gray-200 transition-all hover:bg-gray-800"
            >
                <RefreshCcw class="h-4 w-4" /> Check Status
            </button>
        </div>
    </AppLayout>
</template>
