<script setup lang="ts">
import MobileLayout from '@/layouts/MobileLayout.vue';
import mobileRoutes from '@/routes/mobile';
import mobileOrganizationRoutes from '@/routes/mobile/organizations';
import { Head, router } from '@inertiajs/vue3';
import { Building2, Check } from 'lucide-vue-next';

defineProps<{
    organizations: Array<{ id: string; name: string }>;
    currentOrganizationId: string | null;
}>();

const choose = (organizationId: string) => {
    router.post(mobileOrganizationRoutes.store().url, {
        organization_id: organizationId,
    });
};
</script>

<template>
    <Head title="Choose Organization" />

    <MobileLayout
        title="Choose Organization"
        :back-href="
            currentOrganizationId ? mobileRoutes.dashboard().url : undefined
        "
    >
        <div class="space-y-3 p-4">
            <p class="px-1 pb-1 text-[13px] text-slate-400">
                Which organization's projects do you want to see?
            </p>

            <button
                v-for="organization in organizations"
                :key="organization.id"
                type="button"
                class="flex w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 active:bg-slate-50 dark:border-white/10 dark:bg-slate-900 dark:active:bg-white/5"
                @click="choose(organization.id)"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 dark:bg-white/10"
                    >
                        <Building2 class="h-4 w-4 text-slate-400" />
                    </div>
                    <p
                        class="truncate font-bold text-slate-900 dark:text-white"
                    >
                        {{ organization.name }}
                    </p>
                </div>
                <Check
                    v-if="organization.id === currentOrganizationId"
                    class="h-5 w-5 shrink-0 text-projector-primary-600"
                />
            </button>
        </div>
    </MobileLayout>
</template>
