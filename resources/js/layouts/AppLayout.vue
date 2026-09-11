<script setup lang="ts">
import AiProcessingHeader from '@/components/AiProcessingHeader.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import SessionTimeoutModal from '@/components/SessionTimeoutModal.vue';
import Toaster from '@/components/ui/sonner/Sonner.vue';
import { useGlobalImportActivity } from '@/composables/useGlobalImportActivity';
import {
    useGlobalProcessingBanner,
    BANNER_PRIORITY,
} from '@/composables/useGlobalProcessingBanner';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { watch } from 'vue';
import 'vue-sonner/style.css';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// The only place AiProcessingHeader is ever rendered — every page with its own AI-processing
// state (Dashboard, Projects/Show, Documents/Show, StatusMeetings, ImportKindStep) registers
// into the same shared banner (see useGlobalProcessingBanner.ts) instead of rendering its own,
// so at most one banner is ever visible instead of stacking a page-specific one on top of this
// org-wide fallback.
const { isProcessing: isImportActivityInProgress } = useGlobalImportActivity();
const { activeBanner, setBanner, clearBanner } = useGlobalProcessingBanner();

// Org-wide "an import is running somewhere" indicator — persists across navigation, unlike the
// page-specific banners, which only reflect the current page's own tracked activity. Lowest
// priority: a page's own banner (more specific) always wins over this fallback when both are
// active at once.
watch(
    isImportActivityInProgress,
    (active) => {
        if (active) {
            setBanner('background-fallback', {
                title: 'Import In Progress',
                message:
                    'An import is running in the background — you can keep working.',
                progress: 0,
                priority: BANNER_PRIORITY.BACKGROUND_FALLBACK,
            });
        } else {
            clearBanner('background-fallback');
        }
    },
    { immediate: true },
);
</script>

<template>
    <ImpersonationBanner />
    <SessionTimeoutModal />

    <AppLayout :breadcrumbs="breadcrumbs">
        <AiProcessingHeader
            :is-processing="activeBanner !== null"
            :progress="activeBanner?.progress ?? 0"
            :title="activeBanner?.title ?? 'AI Sync Active'"
            :message="activeBanner?.message ?? ''"
        />

        <slot />

        <Toaster position="bottom-right" richColors />
    </AppLayout>
</template>
