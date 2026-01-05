<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { computed } from 'vue';

interface Props {
    user: any;
    showEmail?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

const { getInitials } = useInitials();

const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);

const userInitials = computed(() => {
    if (props.user.first_name && props.user.last_name) {
        return (props.user.first_name[0] + props.user.last_name[0]).toUpperCase();
    }
    return getInitials(props.user.name);
});
</script>

<template>
    <div class="flex items-center gap-3 overflow-hidden">
        <Avatar class="h-9 w-9 overflow-hidden rounded-xl border border-gray-100 dark:border-gray-800 shadow-sm shrink-0">
            <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
            <AvatarFallback class="rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-xs font-black">
                {{ userInitials }}
            </AvatarFallback>
        </Avatar>

        <div class="flex flex-col min-w-0 leading-tight">
            <span class="text-sm font-black uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400 group-hover/header:text-indigo-600 transition-colors">
                {{ user.name }}
            </span>
            <span v-if="showEmail" class="truncate text-[11px] font-medium text-gray-500 dark:text-gray-400">
                {{ user.email }}
            </span>
        </div>
    </div>
</template>
