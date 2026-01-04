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
import projectRoutes from '@/routes/projects/index';
import projectTypeRoutes from '@/routes/project-types/index';
import roleRoutes from '@/routes/roles/index';

import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';

import { BookOpen, Folder, LayoutGrid, Users, User, Briefcase, Workflow, Settings2 } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

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
    },
    {
        title: 'Projects',
        href: projectRoutes.index(),
        icon: Briefcase,
    },
    {
        title: 'Project Types',
        href: projectTypeRoutes.index(),
        icon: Workflow,
    },
    {
        title: 'Users',
        href: userRoutes.index(),
        icon: User,
    },
    {
        title: 'Roles',
        href: roleRoutes.index(),
        icon: Settings2,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
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
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
