<script setup lang="ts">
import { computed, ref } from 'vue';
import { Search, X } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { addUser } from '@/actions/App/Http/Controllers/OrganizationController';
import UserInfo from '@/components/UserInfo.vue';

const props = defineProps<{
    users: User[];
    organizationId: string;
}>();

const emit = defineEmits<{
    (e: 'userAdded'): void;
}>();

const query = ref('');

const filtered = computed(() => {
    const q = query.value.toLowerCase().trim();
    if (!q) {
        return props.users;
    }
    return props.users.filter(
        (user) =>
            user.name.toLowerCase().includes(q) ||
            user.email.toLowerCase().includes(q),
    );
});

const add = (user: User) => {
    router.post(addUser(props.organizationId).url, { user_id: user.id }, {
        preserveScroll: true,
        onSuccess: () => emit('userAdded'),
    });
};
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="relative">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <Search class="h-4 w-4 text-gray-400" />
            </div>
            <Input
                v-model="query"
                type="text"
                placeholder="Search by name or email..."
                class="h-10 w-full rounded-xl border-gray-200 bg-white pl-10 pr-10 shadow-sm transition-all focus:ring-indigo-500 dark:border-zinc-800 dark:bg-zinc-900"
            />
            <button
                v-if="query"
                @click="query = ''"
                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600"
            >
                <X class="h-4 w-4" />
            </button>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div v-if="filtered.length === 0" class="p-8 text-center">
                <p class="text-sm italic text-gray-500">No users found.</p>
            </div>

            <div v-else class="divide-y divide-gray-50 dark:divide-zinc-800/50">
                <div
                    v-for="user in filtered"
                    :key="user.id"
                    class="flex items-center justify-between p-4 transition-colors hover:bg-gray-50/30 dark:hover:bg-zinc-800/10"
                >
                    <UserInfo :user="user" :show-email="true" />
                    <Button size="sm" variant="outline" class="shrink-0" @click="add(user)">
                        Add
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
