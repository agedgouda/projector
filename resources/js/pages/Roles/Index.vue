<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useForm, Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Trash2, ShieldPlus } from 'lucide-vue-next';
import roleRoutes from '@/routes/roles/index';
import { type BreadcrumbItem } from '@/types';

defineProps<{
    roles: any[];
}>();

const form = useForm({
    name: '',
});

const submit = () => {
    // Access the .url property of the Wayfinder route
    form.post(roleRoutes.store().url, {
        onSuccess: () => form.reset(),
    });
};

const deleteRole = (id: number) => {
    if (confirm('Are you sure you want to delete this role?')) {
        // Access the .url property of the Wayfinder route
        router.delete(roleRoutes.destroy(id).url);
    }
};
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Roles', href: '#' },
];
</script>

<template>
    <Head title="Manage Roles" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 max-w-none">
            <h2 class="text-2xl font-bold mb-6 text-foreground">System Roles</h2>

            <form @submit.prevent="submit" class="flex gap-4 mb-8 items-end">
                <div class="grid gap-2 w-full max-w-sm">
                    <label class="text-sm font-medium text-foreground">New Role Name</label>
                    <Input
                        v-model="form.name"
                        placeholder="e.g. manager, editor..."
                        class="bg-background"
                    />
                </div>
                <Button type="submit" :disabled="form.processing" class="cursor-pointer">
                    <ShieldPlus class="w-4 h-4 mr-2" />
                    Add Role
                </Button>
            </form>

            <div class="border rounded-lg bg-card overflow-hidden max-w-4xl">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Role Name</TableHead>
                            <TableHead>Users Count</TableHead>
                            <TableHead class="text-right pr-6">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="role in roles" :key="role.id">
                            <TableCell class="font-medium capitalize">{{ role.name }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ role.users_count || 0 }}</TableCell>
                            <TableCell class="text-right pr-6">
                                <Button
                                    v-if="role.name !== 'admin'"
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive hover:bg-destructive/10 cursor-pointer"
                                    @click="deleteRole(role.id)"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AppLayout>
</template>
