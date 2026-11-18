<script setup lang="ts">
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import adminOrgRoutes from '@/routes/admin/organizations/index';
import organizationRoutes from '@/routes/organizations/index';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

interface OrgRow {
    id: string;
    name: string;
    membership_tier: 'free' | 'pro' | 'friends_family';
    tier_label: string;
    users_count: number;
    users_denominator: number | null;
    clients_count: number;
    projects_count: number;
    ai_docs_this_month: number;
    ai_docs_limit: number | null;
    created_at: string;
}

const props = defineProps<{
    organizations: OrgRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '' },
    { title: 'Organizations', href: '' },
];

const TIERS = [
    { value: 'pro', label: 'Pro' },
    { value: 'friends_family', label: 'Friends & Family' },
    { value: 'free', label: 'Free' },
];

const TIER_ORDER: Record<string, number> = {
    pro: 0,
    friends_family: 1,
    free: 2,
};

const query = ref('');

const filtered = computed(() => {
    const q = query.value.toLowerCase().trim();
    const rows = q
        ? props.organizations.filter((o) => o.name.toLowerCase().includes(q))
        : props.organizations;

    return [...rows].sort((a, b) => {
        const tierDiff =
            (TIER_ORDER[a.membership_tier] ?? 99) -
            (TIER_ORDER[b.membership_tier] ?? 99);
        return tierDiff !== 0 ? tierDiff : a.name.localeCompare(b.name);
    });
});

const updateTier = (org: OrgRow, tier: string) => {
    router.patch(
        adminOrgRoutes.updateTier({ organization: org.id }).url,
        { membership_tier: tier },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    `${org.name} updated to ${TIERS.find((t) => t.value === tier)?.label}.`,
                ),
        },
    );
};

const goToOrg = (orgId: string) => {
    router.visit(organizationRoutes.index.url(), { data: { org: orgId } });
};
</script>

<template>
    <Head title="Org Admin" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-4 px-6 py-6">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h1
                        class="text-xl font-black tracking-tight text-slate-900 dark:text-white"
                    >
                        Organizations
                    </h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        {{ filtered.length }} of {{ organizations.length }}
                    </p>
                </div>
                <div class="relative w-64">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"
                    />
                    <Input
                        v-model="query"
                        placeholder="Search organizations..."
                        class="h-9 pl-9"
                    />
                </div>
            </div>

            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-slate-100 dark:border-zinc-800"
                        >
                            <th
                                class="px-5 py-3 text-left text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >
                                Organization
                            </th>
                            <th
                                class="px-5 py-3 text-left text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >
                                Tier
                            </th>
                            <th
                                class="px-5 py-3 text-center text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >
                                Users
                            </th>
                            <th
                                class="px-5 py-3 text-center text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >
                                Clients
                            </th>
                            <th
                                class="px-5 py-3 text-center text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >
                                Projects
                            </th>
                            <th
                                class="px-5 py-3 text-center text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >
                                AI Docs (mo.)
                            </th>
                            <th
                                class="px-5 py-3 text-left text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >
                                Created
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="org in filtered"
                            :key="org.id"
                            class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50 dark:border-zinc-800/50 dark:hover:bg-zinc-800/30"
                        >
                            <td class="px-5 py-3.5">
                                <button
                                    type="button"
                                    @click="goToOrg(org.id)"
                                    class="text-left font-semibold text-projector-primary-600 hover:underline dark:text-projector-primary-400"
                                >
                                    {{ org.name }}
                                </button>
                            </td>

                            <td class="px-5 py-3.5">
                                <select
                                    :value="org.membership_tier"
                                    @change="
                                        updateTier(
                                            org,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                    class="cursor-pointer rounded-lg border border-slate-200 bg-transparent px-2 py-1 text-[11px] font-black tracking-widest uppercase focus:ring-2 focus:ring-projector-primary-500 focus:outline-none dark:border-zinc-700"
                                    :class="{
                                        'text-projector-primary-600':
                                            org.membership_tier === 'pro',
                                        'text-emerald-600':
                                            org.membership_tier ===
                                            'friends_family',
                                        'text-slate-500':
                                            org.membership_tier === 'free',
                                    }"
                                >
                                    <option
                                        v-for="tier in TIERS"
                                        :key="tier.value"
                                        :value="tier.value"
                                    >
                                        {{ tier.label }}
                                    </option>
                                </select>
                            </td>

                            <td
                                class="px-5 py-3.5 text-center text-slate-700 dark:text-slate-300"
                            >
                                <span>{{ org.users_count }}</span>
                                <span
                                    v-if="org.users_denominator !== null"
                                    class="text-xs text-slate-400"
                                >
                                    / {{ org.users_denominator }}</span
                                >
                            </td>
                            <td
                                class="px-5 py-3.5 text-center text-slate-700 dark:text-slate-300"
                            >
                                {{ org.clients_count }}
                            </td>
                            <td
                                class="px-5 py-3.5 text-center text-slate-700 dark:text-slate-300"
                            >
                                {{ org.projects_count }}
                            </td>

                            <td class="px-5 py-3.5 text-center">
                                <span
                                    class="font-semibold"
                                    :class="{
                                        'text-red-600':
                                            org.ai_docs_limit !== null &&
                                            org.ai_docs_this_month >=
                                                org.ai_docs_limit,
                                        'text-amber-600':
                                            org.ai_docs_limit !== null &&
                                            org.ai_docs_this_month >=
                                                org.ai_docs_limit * 0.8,
                                        'text-slate-700 dark:text-slate-300':
                                            org.ai_docs_limit === null ||
                                            org.ai_docs_this_month <
                                                org.ai_docs_limit * 0.8,
                                    }"
                                    >{{ org.ai_docs_this_month }}</span
                                >
                                <span class="text-xs text-slate-400">
                                    / {{ org.ai_docs_limit ?? '∞' }}</span
                                >
                            </td>

                            <td class="px-5 py-3.5 text-xs text-slate-400">
                                {{ org.created_at }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
