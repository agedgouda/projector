<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { ShieldAlert } from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';
import userRoutes from '@/routes/users/index';

defineProps<{
    users: User[];
    showAdminToggle?: boolean;
}>();

const toggleAdminStatus = (user: User) => {
    if (user.is_super) return;

    router.put(userRoutes.update(user.id).url, {
        organization_id: user.organization_id,
        is_admin: !user.roles.includes('org-admin')
    }, {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 rounded-xl overflow-hidden shadow-sm">
        <div class="grid grid-cols-[1fr_128px] bg-gray-50/50 dark:bg-zinc-800/50 border-b border-gray-200 dark:border-zinc-800">
            <div class="p-4 pl-8 text-[10px] font-black uppercase tracking-widest text-gray-400">User</div>
            <div class="p-4 pr-12 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Admin</div>
        </div>

        <div class="divide-y divide-gray-50 dark:divide-zinc-800/50">
            <div v-for="user in users" :key="user.row_key"
                class="grid grid-cols-[1fr_128px] items-center hover:bg-gray-50/30 dark:hover:bg-zinc-800/10 transition-colors"
            >
                <div class="p-4 pl-8 min-w-0">
                    <div class="flex items-center gap-3">
                        <UserInfo :user="user" />
                        <div v-if="user.is_super" class="flex items-center gap-1.5 px-2 py-0.5 rounded bg-amber-50 text-amber-600 border border-amber-100 dark:bg-amber-500/10 dark:border-amber-500/20">
                            <ShieldAlert class="w-3 h-3" />
                            <span class="text-[9px] font-black uppercase tracking-tighter">Super</span>
                        </div>
                    </div>
                </div>

                <div class="p-4 pr-12 flex justify-center">
                    <input
                        type="checkbox"
                        :checked="user.roles.includes('org-admin') || user.is_super"
                        :disabled="user.is_super || !showAdminToggle"
                        @change="toggleAdminStatus(user)"
                        class="h-4 w-4 shrink-0 rounded-sm border border-zinc-300 dark:border-zinc-700 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer transition-all shadow-sm"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
