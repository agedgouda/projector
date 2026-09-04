<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

// 1. Import your Wayfinder routes
import { dashboard } from '@/routes';
import importWizardRoutes from '@/routes/import/index';
import organizationRoutes from '@/routes/organizations/index';
import projectRoutes from '@/routes/projects/index';
import statusMeetingsRoutes from '@/routes/status-meetings/index';

import { usePermissions } from '@/composables/usePermissions';
import bugReportsRoutes from '@/routes/bug-reports/index';
import faqRoutes from '@/routes/faq/index';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import {
    Bug,
    Building2,
    CalendarDays,
    Files,
    HelpCircle,
    LayoutGrid,
    TriangleAlert,
    Upload,
    Users,
} from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<AppPageProps>();
const { hasRole } = usePermissions();
const isSuperAdmin = computed(() => hasRole('super-admin'));
const hasOrganizations = computed(
    () => (page.props.auth.user.organizations?.length ?? 0) > 0,
);

// Spans every org the user belongs to (see HandleInertiaRequests::share()) — visiting one
// of these links switches the active org automatically (SetOrganizationContext resolves it
// from the project's own client), so a favorite from another org is never a dead link here.
const favoriteProjects = computed(
    () =>
        (page.props as any).favoriteProjects as Array<{
            id: string;
            name: string;
            logo_url: string | null;
        }>,
);

const canSeeStatusMeetings = computed(
    () => isSuperAdmin.value || hasRole('org-admin') || hasRole('project-lead'),
);

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Status Meetings',
        href: statusMeetingsRoutes.index(),
        icon: CalendarDays,
        hidden: !canSeeStatusMeetings.value,
    },
    {
        title: 'Projects',
        href: projectRoutes.index(),
        icon: Users,
    },
    {
        title: 'Import',
        href: importWizardRoutes.index(),
        icon: Upload,
        hidden: !canSeeStatusMeetings.value,
    },
    {
        title: 'Organizations',
        href: organizationRoutes.index(),
        icon: Building2,
        hidden: !isSuperAdmin.value && !hasOrganizations.value,
    },
    {
        title: 'Bug Reports',
        href: bugReportsRoutes.index(),
        icon: Bug,
        hidden: !isSuperAdmin.value,
    },
];

const filteredNavItems = computed(() =>
    mainNavItems.filter((item) => !item.hidden),
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard().url">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="filteredNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarGroup class="group-data-[collapsible=icon]:p-0">
                <SidebarGroupLabel>Favorites</SidebarGroupLabel>
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem v-if="!favoriteProjects.length">
                            <span
                                class="flex items-center gap-2 px-2 py-1.5 text-xs text-neutral-400 dark:text-neutral-500"
                            >
                                No Favorites Chosen
                            </span>
                        </SidebarMenuItem>
                        <SidebarMenuItem
                            v-for="project in favoriteProjects"
                            :key="project.id"
                        >
                            <SidebarMenuButton
                                class="text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                                as-child
                            >
                                <Link
                                    :href="
                                        projectRoutes.show.url(project.id, {
                                            query: { tab: 'tasks' },
                                        })
                                    "
                                >
                                    <img
                                        v-if="project.logo_url"
                                        :src="project.logo_url"
                                        :alt="project.name"
                                        class="h-4 w-4 shrink-0 rounded-sm object-contain"
                                    />
                                    <Files v-else class="h-4 w-4 shrink-0" />
                                    <span class="truncate">{{
                                        project.name
                                    }}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>

            <SidebarGroup class="group-data-[collapsible=icon]:p-0">
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                class="text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                                as-child
                            >
                                <Link :href="faqRoutes.index().url">
                                    <HelpCircle class="h-4 w-4" />
                                    <span>FAQ</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                class="text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                                as-child
                            >
                                <Link :href="bugReportsRoutes.create().url">
                                    <TriangleAlert class="h-4 w-4" />
                                    <span>Report a Bug</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
