<script setup lang="ts">
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import SessionTimeoutModal from '@/components/SessionTimeoutModal.vue';
import Toaster from '@/components/ui/sonner/Sonner.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import 'vue-sonner/style.css';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <ImpersonationBanner />
    <SessionTimeoutModal />

    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />

        <Toaster position="bottom-right" richColors />
    </AppLayout>
</template>
