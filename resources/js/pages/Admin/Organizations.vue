<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/layouts/AppLayout.vue';
import { Input } from '@/components/ui/input';
import { Search } from 'lucide-vue-next';
import { type BreadcrumbItem } from '@/types';
import adminOrgRoutes from '@/routes/admin/organizations/index';
import organizationRoutes from '@/routes/organizations/index';
import { ref, computed } from 'vue';

interface OrgRow {
    id: string;
    name: string;
    membership_tier: 'free' | 'pro' | 'friends_family';
    tier_label: string;
    users_count: number;
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

const TIER_ORDER: Record<string, number> = { pro: 0, friends_family: 1, free: 2 };

const query = ref('');

const filtered = computed(() => {
    const q = query.value.toLowerCase().trim();
    const rows = q
        ? props.organizations.filter(o => o.name.toLowerCase().includes(q))
        : props.organizations;

    return [...rows].sort((a, b) => {
        const tierDiff = (TIER_ORDER[a.membership_tier] ?? 99) - (TIER_ORDER[b.membership_tier] ?? 99);
        return tierDiff !== 0 ? tierDiff : a.name.localeCompare(b.name);
    });
});

const updateTier = (org: OrgRow, tier: string) => {
    router.patch(adminOrgRoutes.updateTier({ organization: org.id }).url, { membership_tier: tier }, {
        preserveScroll: true,
        onSuccess: () => toast.success(`${org.name} updated to ${TIERS.find(t => t.value === tier)?.label}.`),
    });
};

const goToOrg = (orgId: string) => {
    router.visit(organizationRoutes.index.url(), { data: { org: orgId } });
};
</script>

<template>
    <Head title="Org Admin" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full px-6 py-6 space-y-4">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h1 class="text-xl font-black tracking-tight text-slate-900 dark:text-white">Organizations</h1>
                    <p class="text-sm text-slate-500 mt-0.5">{{ filtered.length }} of {{ organizations.length }}</p>
                </div>
                <div class="relative w-64">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 pointer-events-none" />
                    <Input v-model="query" placeholder="Search organizations..." class="pl-9 h-9" />
                </div>
            </div>

            <div class="bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-zinc-800">
                            <th class="text-left px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Organization</th>
                            <th class="text-left px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Tier</th>
                            <th class="text-center px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Users</th>
                            <th class="text-center px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Clients</th>
                            <th class="text-center px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Projects</th>
                            <th class="text-center px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-400">AI Docs (mo.)</th>
                            <th class="text-left px-5 py-3 text-[10px] font-black uppercase tracking-widest text-slate-400">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="org in filtered"
                            :key="org.id"
                            class="border-b border-slate-50 dark:border-zinc-800/50 last:border-0 hover:bg-slate-50/50 dark:hover:bg-zinc-800/30 transition-colors"
                        >
                            <td class="px-5 py-3.5">
                                <button
                                    type="button"
                                    @click="goToOrg(org.id)"
                                    class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline text-left"
                                >{{ org.name }}</button>
                            </td>

                            <td class="px-5 py-3.5">
                                <select
                                    :value="org.membership_tier"
                                    @change="updateTier(org, ($event.target as HTMLSelectElement).value)"
                                    class="text-[11px] font-black uppercase tracking-widest rounded-lg border border-slate-200 dark:border-zinc-700 bg-transparent px-2 py-1 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer"
                                    :class="{
                                        'text-indigo-600': org.membership_tier === 'pro',
                                        'text-emerald-600': org.membership_tier === 'friends_family',
                                        'text-slate-500': org.membership_tier === 'free',
                                    }"
                                >
                                    <option v-for="tier in TIERS" :key="tier.value" :value="tier.value">{{ tier.label }}</option>
                                </select>
                            </td>

                            <td class="px-5 py-3.5 text-center text-slate-700 dark:text-slate-300">{{ org.users_count }}</td>
                            <td class="px-5 py-3.5 text-center text-slate-700 dark:text-slate-300">{{ org.clients_count }}</td>
                            <td class="px-5 py-3.5 text-center text-slate-700 dark:text-slate-300">{{ org.projects_count }}</td>

                            <td class="px-5 py-3.5 text-center">
                                <span
                                    class="font-semibold"
                                    :class="{
                                        'text-red-600': org.ai_docs_limit !== null && org.ai_docs_this_month >= org.ai_docs_limit,
                                        'text-amber-600': org.ai_docs_limit !== null && org.ai_docs_this_month >= org.ai_docs_limit * 0.8,
                                        'text-slate-700 dark:text-slate-300': org.ai_docs_limit === null || org.ai_docs_this_month < org.ai_docs_limit * 0.8,
                                    }"
                                >{{ org.ai_docs_this_month }}</span>
                                <span class="text-slate-400 text-xs"> / {{ org.ai_docs_limit ?? '∞' }}</span>
                            </td>

                            <td class="px-5 py-3.5 text-slate-400 text-xs">{{ org.created_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
