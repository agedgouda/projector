<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import OrgUserTable from '@/components/user/OrgUserTable.vue';
import OrgSwitcher from '@/components/user/OrgSwitcher.vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import { Building2, Globe, Mail, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';
import organizationRoutes from '@/routes/organizations/index';



defineProps<{
    organizations: Organization[];
    currentOrg: Organization;
}>();

const page = usePage<AppPageProps>();

// Helper to check for super-admin role
const isSuperAdmin = page.props.auth.user.roles?.includes('super-admin');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Organization Profile', href: '' }
];

const handleOrgSwitch = (id: string) => {
    router.get(organizationRoutes.index.url(), { org: id }, {
        preserveState: false,
        preserveScroll: true
    });
};
</script>

<template>
    <Head title="Organization Profile" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-8 max-w-5xl mx-auto w-full space-y-8">

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white uppercase flex items-center gap-3">
                        <Building2 class="w-8 h-8 text-indigo-500" />
                        Organization Profile
                    </h1>
                </div>

                <div class="flex items-center gap-3">
                    <Button
                        v-if="isSuperAdmin"
                        variant="outline"
                        as-child
                        class="h-12 px-4 rounded-xl border-dashed border-gray-300 dark:border-zinc-700 hover:border-indigo-500 transition-colors"
                    >
                        <Link :href="organizationRoutes.create.url()">
                            <Plus class="w-4 h-4 mr-2 text-indigo-500" />
                            <span class="text-[10px] font-black uppercase tracking-widest">New Org</span>
                        </Link>
                    </Button>

                    <OrgSwitcher
                        :organizations="organizations"
                        :current-org="currentOrg"
                        @switch="handleOrgSwitch"
                    />
                </div>
            </div>

            <div class="bg-indigo-600 rounded-2xl p-8 text-white shadow-xl shadow-indigo-500/20 relative overflow-hidden">
                <div class="relative z-10">
                    <h2 class="text-3xl font-black uppercase tracking-tighter mb-4">{{ currentOrg.name }}</h2>
                    <div class="flex flex-wrap gap-6 text-indigo-100 font-medium text-sm">
                        <span v-if="currentOrg.website" class="flex items-center gap-2">
                            <Globe class="h-4 w-4" /> {{ currentOrg.website }}
                        </span>
                        <span v-if="currentOrg.email" class="flex items-center gap-2">
                            <Mail class="h-4 w-4" /> {{ currentOrg.email }}
                        </span>
                    </div>
                </div>
                <Building2 class="absolute -right-12 -bottom-12 w-64 h-64 text-white/10 rotate-12" />
            </div>

            <div>
                <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-4">Team Directory</h3>
                <OrgUserTable
                    :users="currentOrg.users || []"
                    :show-admin-toggle="currentOrg.can?.manage_users ?? false"
                />
            </div>
        </div>
    </AppLayout>
</template>
