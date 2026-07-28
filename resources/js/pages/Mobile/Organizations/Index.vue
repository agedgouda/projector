<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import MobileLayout from '@/layouts/MobileLayout.vue';
import { Check, Building2 } from 'lucide-vue-next';
import mobileRoutes from '@/routes/mobile';
import mobileOrganizationRoutes from '@/routes/mobile/organizations';

defineProps<{
    organizations: Array<{ id: string; name: string }>;
    currentOrganizationId: string | null;
}>();

const choose = (organizationId: string) => {
    router.post(mobileOrganizationRoutes.store().url, { organization_id: organizationId });
};
</script>

<template>
    <Head title="Choose Organization" />

    <MobileLayout
        title="Choose Organization"
        :back-href="currentOrganizationId ? mobileRoutes.dashboard().url : undefined"
    >
        <div class="p-4 space-y-3">
            <p class="text-[13px] text-slate-400 px-1 pb-1">Which organization's projects do you want to see?</p>

            <button
                v-for="organization in organizations"
                :key="organization.id"
                type="button"
                class="w-full flex items-center justify-between gap-3 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-white/10 p-4 active:bg-slate-50 dark:active:bg-white/5"
                @click="choose(organization.id)"
            >
                <div class="flex items-center gap-3 min-w-0">
                    <div class="h-9 w-9 rounded-full bg-slate-100 dark:bg-white/10 flex items-center justify-center shrink-0">
                        <Building2 class="w-4 h-4 text-slate-400" />
                    </div>
                    <p class="font-bold text-slate-900 dark:text-white truncate">{{ organization.name }}</p>
                </div>
                <Check v-if="organization.id === currentOrganizationId" class="w-5 h-5 text-projector-primary-600 shrink-0" />
            </button>
        </div>
    </MobileLayout>
</template>
