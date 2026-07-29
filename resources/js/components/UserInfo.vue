<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { computed } from 'vue';

interface Props {
    user: any;
    showEmail?: boolean;
    compact?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
    compact: false,
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
    <div class="flex items-center gap-3 overflow-hidden" :class="{ 'gap-2': compact }">
        <Avatar
            class="overflow-hidden rounded-xl border border-gray-100 dark:border-gray-800 shadow-sm shrink-0"
            :class="compact ? 'h-6 w-6 rounded-lg' : 'h-9 w-9'"
        >
            <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
            <AvatarFallback
                class="bg-projector-primary-50 dark:bg-projector-primary-500/10 text-projector-primary-600 dark:text-projector-primary-400 font-black"
                :class="compact ? 'rounded-lg text-[9px]' : 'rounded-xl text-xs'"
            >
                {{ userInitials }}
            </AvatarFallback>
        </Avatar>

        <div class="flex flex-col min-w-0 leading-tight">
            <span
                class="font-black uppercase text-gray-500 dark:text-gray-400 group-hover/header:text-projector-primary-600 transition-colors"
                :class="compact ? 'text-[11px] tracking-[0.1em]' : 'text-sm tracking-[0.2em]'"
            >
                {{ user.name }}
            </span>
            <span v-if="showEmail" class="truncate font-medium text-gray-500 dark:text-gray-400" :class="compact ? 'text-[10px]' : 'text-[11px]'">
                {{ user.email }}
            </span>
        </div>
    </div>
</template>
