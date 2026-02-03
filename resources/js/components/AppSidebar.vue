<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

// 1. Import your Wayfinder routes
import { dashboard } from '@/routes';
import clientRoutes from '@/routes/clients/index';
import userRoutes from '@/routes/users/index';
import projectTypeRoutes from '@/routes/project-types/index';
import roleRoutes from '@/routes/roles/index';
import aiRoutes from '@/routes/ai-templates/index';

import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { BookOpen, LayoutGrid, Users, User, Workflow, Settings2, Sparkles } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';


const page = usePage<AppPageProps>();
const userRoles = computed(() => page.props.auth.user.roles);
const isSuperAdmin = computed(() => userRoles.value.includes('super-admin'));
const isOrgAdmin = computed(() => userRoles.value.includes('org-admin'));
const hasClients = computed(() => (page.props.auth.user.clients?.length ?? 0) > 0);
const canAccessWorkspace = computed(() => isSuperAdmin.value || hasClients.value);

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Clients',
        href: clientRoutes.index(),
        icon: Users,
        hidden: !canAccessWorkspace.value,
    },
    {
        title: 'Project Types',
        href: projectTypeRoutes.index(),
        icon: Workflow,
        hidden: !isSuperAdmin.value,
    },
    {
        title: 'Users',
        href: userRoutes.index(),
        icon: User,
        hidden: !isSuperAdmin.value && !isOrgAdmin.value,
    },
    {
        title: 'Roles',
        href: roleRoutes.index(),
        icon: Settings2,
        hidden: !isSuperAdmin.value,
    },
    {
        title: 'AI Workflows',
        href: aiRoutes.index(),
        icon: Sparkles,
        hidden: !isSuperAdmin.value,
    },
];

const filteredNavItems = computed(() => mainNavItems.filter(item => !item.hidden));

const footerNavItems: NavItem[] = [
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
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
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
