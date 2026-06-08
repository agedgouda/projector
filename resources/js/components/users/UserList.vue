<script setup lang="ts">
import { computed, ref } from 'vue';
import { Search, X } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { router, usePage } from '@inertiajs/vue3';
import { addUser } from '@/actions/App/Http/Controllers/OrganizationController';
import UserInfo from '@/components/UserInfo.vue';
import UpgradeModal from '@/components/UpgradeModal.vue';
import { FLAT_ROW_HOVER, FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';
import type { AppPageProps } from '@/types';

const props = defineProps<{
    users: User[];
    organizationId: string;
}>();

const emit = defineEmits<{
    (e: 'userAdded'): void;
}>();

const query = ref('');

const eligible = computed(() =>
    [...props.users]
        .filter((user) => !user.roles?.includes('super-admin'))
        .sort((a, b) => a.name.localeCompare(b.name))
);

const filtered = computed(() => {
    const q = query.value.toLowerCase().trim();
    if (!q) {
        return eligible.value;
    }
    return eligible.value.filter(
        (user) =>
            user.name.toLowerCase().includes(q) ||
            user.email.toLowerCase().includes(q),
    );
});

const page = usePage<AppPageProps>();
const atLimit = computed(() => (page.props as any).orgMembership?.at_limit ?? {});

const showUpgradeModal = ref(false);

const add = (user: User) => {
    if (atLimit.value.users) {
        showUpgradeModal.value = true;
        return;
    }
    router.post(addUser(props.organizationId).url, { user_id: user.id }, {
        preserveScroll: true,
        onSuccess: () => emit('userAdded'),
    });
};
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="relative w-full md:w-80 lg:w-96 group">
            <Search :class="FLAT_SEARCH_ICON" />
            <Input
                v-model="query"
                type="text"
                placeholder="Search by name or email..."
                :class="[FLAT_SEARCH_INPUT, 'pr-7']"
            />
            <button
                v-if="query"
                @click="query = ''"
                class="absolute right-1 top-1/2 -translate-y-1/2 p-0.5 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-full transition-colors"
            >
                <X class="h-3 w-3 text-slate-500" />
            </button>
        </div>

        <div v-if="filtered.length === 0" class="py-8 text-center">
            <p class="text-[13px] italic text-slate-400">No users found.</p>
        </div>

        <div v-else class="grid gap-0.5">
            <div
                v-for="user in filtered"
                :key="user.id"
                :class="['flex items-center justify-between gap-3 h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]"
            >
                <UserInfo :user="user" :show-email="true" />
                <Button size="sm" variant="outline" class="shrink-0" @click="add(user)">
                    Add
                </Button>
            </div>
        </div>
    </div>

    <UpgradeModal
        :open="showUpgradeModal"
        limit-key="users"
        @close="showUpgradeModal = false"
    />
</template>
