<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger
} from '@/components/ui/dropdown-menu';
import { Settings2, Loader2 } from 'lucide-vue-next';
import { ref } from 'vue';
import userRoutes from '@/routes/users/index';

const props = defineProps<{
    // Using the global User type
    user: User & { roles: string[] };
    allRoles: string[];
}>();

const isUpdating = ref(false);

const toggleRole = (roleName: string) => {
    isUpdating.value = true;

    // Wayfinder pattern: accessing the url property of the route definition
    router.post(userRoutes.roles.update(props.user.id).url, {
        role: roleName
    }, {
        preserveScroll: true,
        onFinish: () => isUpdating.value = false,
    });
};
</script>

<template>
    <div class="flex items-center gap-2">
        <div class="flex flex-wrap gap-1">
            <Badge
                v-for="role in user.roles"
                :key="role"
                variant="outline"
                class="capitalize bg-primary/5 text-primary border-primary/20"
            >
                {{ role }}
            </Badge>
            <span v-if="user.roles.length === 0" class="text-xs text-muted-foreground italic">
                No roles assigned
            </span>
        </div>

        <DropdownMenu>
            <DropdownMenuTrigger :disabled="isUpdating" class="p-1 rounded-md hover:bg-accent transition-colors cursor-pointer">
                <Loader2 v-if="isUpdating" class="h-4 w-4 animate-spin text-muted-foreground" />
                <Settings2 v-else class="h-4 w-4 text-muted-foreground" />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" class="w-48">
                <DropdownMenuLabel>Manage Roles</DropdownMenuLabel>
                <DropdownMenuSeparator />

                <DropdownMenuCheckboxItem
                    v-for="role in allRoles"
                    :key="role"
                    :checked="user.roles.includes(role)"
                    @select.prevent="toggleRole(role)"
                    class="capitalize cursor-pointer"
                >
                    {{ role }}
                </DropdownMenuCheckboxItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
