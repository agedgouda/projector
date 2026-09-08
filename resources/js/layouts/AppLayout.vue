<script setup lang="ts">
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import SessionTimeoutModal from '@/components/SessionTimeoutModal.vue';
import Toaster from '@/components/ui/sonner/Sonner.vue';
import { useGlobalImportActivity } from '@/composables/useGlobalImportActivity';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import 'vue-sonner/style.css';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Org-wide "an import is running somewhere" indicator — persists across navigation, unlike the
// per-project AiProcessingHeader instances individual pages (Projects/Show, Dashboard, etc.)
// already render for their own detailed progress. See useGlobalImportActivity.ts.
const { isProcessing: isImportActivityInProgress } = useGlobalImportActivity();
</script>

<template>
    <ImpersonationBanner />
    <SessionTimeoutModal />
    <AiProcessingHeader
        :is-processing="isImportActivityInProgress"
        :progress="0"
        title="Import In Progress"
        message="An import is running in the background — you can keep working."
    />

    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />

        <Toaster position="bottom-right" richColors />
    </AppLayout>
</template>
