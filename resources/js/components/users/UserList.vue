<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Search, X, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { router, usePage } from '@inertiajs/vue3';
import { addUser } from '@/actions/App/Http/Controllers/OrganizationController';
import UserInfo from '@/components/UserInfo.vue';
import UpgradeModal from '@/components/UpgradeModal.vue';
import { FLAT_ROW_HOVER, FLAT_SEARCH_ICON, FLAT_SEARCH_INPUT } from '@/lib/flat-ui';
import type { AppPageProps } from '@/types';

const PER_PAGE = 8;
const ROW_HEIGHT_REM = 2.25; // h-9
const ROW_GAP_REM = 0.125; // gap-0.5
const LIST_MIN_HEIGHT = `${PER_PAGE * ROW_HEIGHT_REM + (PER_PAGE - 1) * ROW_GAP_REM}rem`;

const props = defineProps<{
    users: User[];
    organizationId: string;
}>();

const emit = defineEmits<{
    (e: 'userAdded'): void;
}>();

const query = ref('');
const pageNum = ref(1);

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

watch(filtered, () => {
    pageNum.value = 1;
});

const totalPages = computed(() => Math.max(1, Math.ceil(filtered.value.length / PER_PAGE)));

const paginated = computed(() => {
    const start = (pageNum.value - 1) * PER_PAGE;
    return filtered.value.slice(start, start + PER_PAGE);
});

const inertiaPage = usePage<AppPageProps>();
const atLimit = computed(() => (inertiaPage.props as any).orgMembership?.at_limit ?? {});

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

        <div :style="{ minHeight: LIST_MIN_HEIGHT }" class="flex flex-col">
            <div v-if="filtered.length === 0" class="flex flex-1 items-center justify-center">
                <p class="text-[13px] italic text-slate-400">No users found.</p>
            </div>

            <div v-else class="grid gap-0.5 content-start">
                <div
                    v-for="user in paginated"
                    :key="user.id"
                    :class="['flex items-center justify-between gap-3 h-9 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]"
                >
                    <UserInfo :user="user" :show-email="true" compact />
                    <Button size="sm" variant="outline" class="h-6 px-2 text-xs shrink-0" @click="add(user)">
                        Add
                    </Button>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between pt-1">
            <span class="text-[11px] font-medium text-slate-400">
                Page {{ pageNum }} of {{ totalPages }}
            </span>
            <div class="flex items-center gap-1">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-7 w-7 p-0"
                    :disabled="pageNum <= 1"
                    @click="pageNum--"
                >
                    <ChevronLeft class="h-4 w-4" />
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-7 w-7 p-0"
                    :disabled="pageNum >= totalPages"
                    @click="pageNum++"
                >
                    <ChevronRight class="h-4 w-4" />
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
