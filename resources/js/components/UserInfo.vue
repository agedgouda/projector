<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { computed } from 'vue';

interface Props {
    user: User; // Global type from types/index.d.ts
    showEmail?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

const { getInitials } = useInitials();

// Compute whether we should show the avatar image
const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);

/**
 * Fallback to manual initials if the helper expects a single string
 * and user.name isn't behaving.
 */
const userInitials = computed(() => {
    if (props.user.first_name && props.user.last_name) {
        return (props.user.first_name[0] + props.user.last_name[0]).toUpperCase();
    }
    return getInitials(props.user.name);
});
</script>

<template>
    <Avatar class="h-8 w-8 overflow-hidden rounded-lg">
        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
        <AvatarFallback class="rounded-lg bg-sidebar-accent text-sidebar-accent-foreground text-xs font-medium">
            {{ userInitials }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-semibold">{{ user.name }}</span>
        <span v-if="showEmail" class="truncate text-xs text-muted-foreground">
            {{ user.email }}
        </span>
    </div>
</template>
