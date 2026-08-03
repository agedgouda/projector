<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import OrgSwitcher from '@/components/user/OrgSwitcher.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItemType } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage<AppPageProps>();
const organizations = computed(() => (page.props as any).organizations ?? []);
const currentOrg = computed(
    () =>
        organizations.value.find(
            (org: any) => org.id === page.props.auth.active_org_id,
        ) ?? null,
);

// Switching orgs always lands on the Dashboard rather than reloading the current URL: the page
// being viewed (a project, a client, a document...) belongs to whichever org was active before
// the switch, so staying on it either keeps showing that org's content or 403s outright. A
// middleware (EnsureUserCanAccessClient) also auto-activates whatever org owns the resource in
// the current URL, which would silently override an explicit switch if we stayed put.
const handleOrgSwitch = (orgId: string | number) => {
    router.get(
        dashboard().url,
        { org: orgId },
        {
            preserveState: false,
            preserveScroll: true,
        },
    );
};
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <OrgSwitcher
            v-if="organizations.length > 0"
            :organizations="organizations"
            :current-org="currentOrg"
            @switch="handleOrgSwitch"
        />
    </header>
</template>
