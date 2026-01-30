<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { ShieldAlert, RefreshCcw } from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';

defineProps<{
    message: string;
}>();

const refresh = () => router.get('/dashboard');
</script>

<template>
    <AppLayout title="Access Pending">
        <div class="flex flex-col items-center justify-center min-h-[70vh] p-8">
            <div class="w-20 h-20 bg-amber-50 rounded-full flex items-center justify-center mb-6">
                <ShieldAlert class="w-10 h-10 text-amber-600" />
            </div>

            <h1 class="text-2xl font-black text-gray-900 mb-2">Access Restricted</h1>
            <p class="text-gray-500 max-w-sm text-center mb-8">
                {{ message }}
            </p>

            <button
                @click="refresh"
                class="flex items-center gap-2 px-6 py-3 bg-gray-900 text-white rounded-2xl font-bold hover:bg-gray-800 transition-all shadow-lg shadow-gray-200"
            >
                <RefreshCcw class="w-4 h-4" /> Check Status
            </button>
        </div>
    </AppLayout>
</template>
