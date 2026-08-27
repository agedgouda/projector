<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { Link, router } from '@inertiajs/vue3';
import {
    LogOut,
    Settings,
    Building2,
    Workflow,
    User as UserIcon,
    Settings2,
} from 'lucide-vue-next';
import { computed } from 'vue';

import adminOrgRoutes from '@/routes/admin/organizations/index';
import organizationRoutes from '@/routes/organizations/index';
import roleRoutes from '@/routes/roles/index';
import transformationLibraryRoutes from '@/routes/transformation-library/index';
import userRoutes from '@/routes/users/index';

interface Props {
    user: User;
}

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();

const { hasRole } = usePermissions();
const isSuperAdmin = computed(() => hasRole('super-admin'));
const isOrgAdmin = computed(() => hasRole('org-admin'));
const canSeeTransformations = computed(
    () => isSuperAdmin.value || isOrgAdmin.value,
);
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="organizationRoutes.index()" prefetch as="button">
                <Building2 class="mr-2 h-4 w-4" />
                Organization
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch as="button">
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <template v-if="canSeeTransformations || isSuperAdmin">
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
            <DropdownMenuItem v-if="canSeeTransformations" :as-child="true">
                <Link class="block w-full cursor-pointer" :href="transformationLibraryRoutes.index()" prefetch as="button">
                    <Workflow class="mr-2 h-4 w-4" />
                    Transformations
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem v-if="isSuperAdmin" :as-child="true">
                <Link class="block w-full cursor-pointer" :href="userRoutes.index()" prefetch as="button">
                    <UserIcon class="mr-2 h-4 w-4" />
                    Users
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem v-if="isSuperAdmin" :as-child="true">
                <Link class="block w-full cursor-pointer" :href="roleRoutes.index()" prefetch as="button">
                    <Settings2 class="mr-2 h-4 w-4" />
                    Roles
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem v-if="isSuperAdmin" :as-child="true">
                <Link class="block w-full cursor-pointer" :href="adminOrgRoutes.index()" prefetch as="button">
                    <Building2 class="mr-2 h-4 w-4" />
                    Org Admin
                </Link>
            </DropdownMenuItem>
        </DropdownMenuGroup>
    </template>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full text-destructive focus:text-destructive cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
