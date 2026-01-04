<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import UserInfo from '@/components/UserInfo.vue';
import RoleManager from './Partials/RoleManager.vue'; // Import our new component
import { Head } from '@inertiajs/vue3';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow
} from '@/components/ui/table';
import { type BreadcrumbItem } from '@/types';

interface UserListItem extends User {
    roles: string[];
}

defineProps<{
    users: UserListItem[];
    allRoles: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '#' },
];
</script>

<template>
    <Head title="User Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-none">
            <h2 class="text-2xl font-bold mb-6 text-foreground">User Management</h2>

            <div class="border rounded-lg bg-card overflow-hidden max-w-5xl">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>User</TableHead>
                            <TableHead>Email</TableHead>
                            <TableHead>Roles</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="user in users" :key="user.id">
                            <TableCell>
                                <div class="flex items-center gap-3">
                                    <UserInfo :user="user" />
                                </div>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ user.email }}
                            </TableCell>
                            <TableCell>
                                <RoleManager
                                    :user="user"
                                    :all-roles="allRoles"
                                />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AppLayout>
</template>
