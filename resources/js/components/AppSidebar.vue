<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

// 1. Import your Wayfinder routes
import { dashboard } from '@/routes';
import userRoutes from '@/routes/users/index';
import projectRoutes from '@/routes/projects/index';
import projectTypeRoutes from '@/routes/project-types/index';
import organizationRoutes from '@/routes/organizations/index';
import statusMeetingsRoutes from '@/routes/status-meetings/index';
import roleRoutes from '@/routes/roles/index';
import aiRoutes from '@/routes/ai-templates/index';

import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import bugReportsRoutes from '@/routes/bug-reports/index';
import adminOrgRoutes from '@/routes/admin/organizations/index';
import faqRoutes from '@/routes/faq/index';
import { usePermissions } from '@/composables/usePermissions';

import { Bug, LayoutGrid, Users, User, Workflow, Settings2, Sparkles, Building2, CalendarDays, TriangleAlert, HelpCircle, Newspaper } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<AppPageProps>();
const { hasRole } = usePermissions();
const isSuperAdmin = computed(() => hasRole('super-admin'));
const isOrgAdmin = computed(() => hasRole('org-admin'));
const hasOrganizations = computed(() => (page.props.auth.user.organizations?.length ?? 0) > 0);


const canSeeStatusMeetings = computed(() =>
    isSuperAdmin.value || hasRole('org-admin') || hasRole('project-lead')
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
        title: 'Workflows',
        href: projectTypeRoutes.index(),
        icon: Workflow,
        hidden: !isSuperAdmin.value && !isOrgAdmin.value,
        children: [
            {
                title: 'Project Types',
                href: projectTypeRoutes.index(),
            },
            {
                title: 'AI Workflows',
                href: aiRoutes.index(),
            },
        ],
    },
    {
        title: 'Users',
        href: userRoutes.index(),
        icon: User,
        hidden: !isSuperAdmin.value,
    },
    {
        title: 'Roles',
        href: roleRoutes.index(),
        icon: Settings2,
        hidden: !isSuperAdmin.value,
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
    {
        title: 'Org Admin',
        href: adminOrgRoutes.index(),
        icon: Building2,
        hidden: !isSuperAdmin.value,
    },
];

const filteredNavItems = computed(() => mainNavItems.filter(item => !item.hidden));

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
                                <Link href="/blog">
                                    <Newspaper class="h-4 w-4" />
                                    <span>Blog</span>
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
