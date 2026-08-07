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

// Strictly `=== false`, not just falsy — `has_password` is only ever present at all on
// user records fetched for a members list (see UserCollection::transformUser, which sets
// it true for every real account — under the current registration flow a User row is
// never created without a password) and on the pending-invitation rows in
// OrgInvitationTable.vue (always false there, since an invitation isn't a real account
// yet). Contexts like the current viewer's own nav-menu avatar never include the field,
// so it stays `undefined` there and this never dims your own avatar. This avoids the
// cold-start problem a "last login" timestamp would have had: it would start out null
// for every existing user the moment such a column was introduced, incorrectly flagging
// already-active accounts as never having logged in until their next login.
const hasNotLoggedIn = computed(() => props.user.has_password === false);
</script>

<template>
    <div class="flex items-center gap-3 overflow-hidden" :class="{ 'gap-2': compact }">
        <span class="contents" :title="hasNotLoggedIn ? 'Hasn\'t logged in yet' : undefined">
            <Avatar
                class="overflow-hidden rounded-xl border border-gray-100 dark:border-gray-800 shadow-sm shrink-0 transition-all duration-300"
                :class="[compact ? 'h-6 w-6 rounded-lg' : 'h-9 w-9', { 'grayscale opacity-50': hasNotLoggedIn }]"
            >
                <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
                <AvatarFallback
                    class="bg-projector-primary-50 dark:bg-projector-primary-500/10 text-projector-primary-600 dark:text-projector-primary-400 font-black"
                    :class="compact ? 'rounded-lg text-[9px]' : 'rounded-xl text-xs'"
                >
                    {{ userInitials }}
                </AvatarFallback>
            </Avatar>
        </span>

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
