<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import 'vue-sonner/style.css'
import Toaster from '@/components/ui/sonner/Sonner.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <ImpersonationBanner />

    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />

        <Toaster position="bottom-right" richColors />
    </AppLayout>

</template>
