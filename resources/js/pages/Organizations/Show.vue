<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { ref, computed } from 'vue';
import ResourceSearch from '@/components/ResourceSearch.vue';
import OrgUserTable from '@/components/user/OrgUserTable.vue';
import OrgInvitationTable from '@/components/user/OrgInvitationTable.vue';
import OrgSwitcher from '@/components/user/OrgSwitcher.vue';
import ClientList from '@/components/clients/ClientList.vue';
import OrganizationForm from './Partials/OrganizationForm.vue';
import { Head, Link, router, usePage, useForm } from '@inertiajs/vue3';
import { Building2, Globe, Mail, Plus, UserPlus, Users, SlidersHorizontal, Briefcase, Cpu, AlertTriangle } from 'lucide-vue-next';
import type { BreadcrumbItem, AppPageProps } from '@/types';
import organizationRoutes from '@/routes/organizations/index';
import {
    Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle
} from "@/components/ui/dialog";
import UserList from '@/components/users/UserList.vue';
import { store as inviteUser } from '@/actions/App/Http/Controllers/InvitationController';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import UpgradeModal from '@/components/UpgradeModal.vue';


const props = defineProps<{
    organizations: Organization[];
    users: User[];
    currentOrg: Organization & {
        logo_url?: string | null;
        llm_config_form?: { model: string; host: string; has_key: boolean };
        vector_config_form?: { model: string; host: string; has_key: boolean };
        meeting_config_form?: {
            account_id: string;
            tenant_id: string;
            client_id: string;
            client_secret: string;
            service_account_email: string;
            impersonate_email: string;
            private_key: string;
            has_client_secret?: boolean;
            has_private_key?: boolean;
        };
    };
    allRoles: string[];
    invitations: OrganizationInvitation[];
    clients: Client[];
    projectTypes: ProjectType[];
    usageTotals: { documents_processed: number; cost_usd: number };
    usageByClient: Record<string, {
        documents_processed: number;
        cost_usd: number;
        projects: { project_id: string; documents_processed: number; cost_usd: number }[];
    }>;
}>();

const page = usePage<AppPageProps>();

// Helper to check for super-admin role
const isSuperAdmin = page.props.auth.user.roles?.includes('super-admin');
const isOrgAdmin = page.props.auth.user.roles?.includes('org-admin');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Organization Profile', href: '' }
];

const handleOrgSwitch = (id: string) => {
    router.get(organizationRoutes.index.url(), { org: id }, {
        preserveState: false,
        preserveScroll: true
    });
};

const activeTab = ref<'team' | 'clients' | 'configuration' | 'usage'>('team');

const formatDocs = (n: number) =>
    `${n.toLocaleString()} ${n === 1 ? 'document' : 'documents'}`;

const clientUsageRows = computed(() => {
    return Object.entries(props.usageByClient).map(([clientId, data]) => {
        const client = props.clients.find(c => c.id === clientId);
        return {
            clientId,
            clientName: client?.company_name ?? 'Unknown Client',
            ...data,
            projects: data.projects.map(p => {
                const project = props.clients
                    .flatMap(c => c.projects ?? [])
                    .find((pr: any) => pr.id === p.project_id);
                return { ...p, projectName: project?.name ?? 'Unknown Project' };
            }),
        };
    }).sort((a, b) => b.cost_usd - a.cost_usd);
});
const sortedOrgUsers = computed(() =>
    [...(props.currentOrg.users ?? [])].sort((a, b) => a.name.localeCompare(b.name))
);
const filteredUsers = ref<User[]>(sortedOrgUsers.value);

const isAddUserListOpen = ref(false);
const isInviteModalOpen = ref(false);
const showUpgradeModal = ref(false);
const showBillingWarningModal = ref(false);
const pendingUserAction = ref<'add' | 'invite' | null>(null);

const atLimit = computed(() => (page.props as any).orgMembership?.at_limit ?? {});
const orgMembership = computed(() => (page.props as any).orgMembership ?? null);

const currentUserCount = computed(() => orgMembership.value?.usage?.users ?? 0);
const effectiveUserCount = computed(() => currentUserCount.value + props.invitations.length);
const plannedUserCount = computed<number | null>(() => (props.currentOrg as any).planned_user_count ?? null);
const remainingUsers = computed(() =>
    plannedUserCount.value !== null ? Math.max(0, plannedUserCount.value - effectiveUserCount.value) : null
);
const isOverPlannedCount = computed(() =>
    orgMembership.value?.tier !== 'friends_family' &&
    plannedUserCount.value !== null &&
    effectiveUserCount.value >= plannedUserCount.value
);

const openAddUser = () => {
    if (atLimit.value.users) {
        showUpgradeModal.value = true;
        return;
    }
    if (isOverPlannedCount.value) {
        pendingUserAction.value = 'add';
        showBillingWarningModal.value = true;
        return;
    }
    isAddUserListOpen.value = true;
};

const openInviteUser = () => {
    if (atLimit.value.users) {
        showUpgradeModal.value = true;
        return;
    }
    if (isOverPlannedCount.value) {
        pendingUserAction.value = 'invite';
        showBillingWarningModal.value = true;
        return;
    }
    isInviteModalOpen.value = true;
};

const confirmBillingWarning = () => {
    showBillingWarningModal.value = false;
    if (pendingUserAction.value === 'add') {
        isAddUserListOpen.value = true;
    } else if (pendingUserAction.value === 'invite') {
        isInviteModalOpen.value = true;
    }
    pendingUserAction.value = null;
};

const cancelBillingWarning = () => {
    showBillingWarningModal.value = false;
    pendingUserAction.value = null;
};

const inviteForm = useForm({ email: '', role: 'team-member' });

const submitInvite = (orgId: string) => {
    inviteForm.post(inviteUser(orgId).url, {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset();
            isInviteModalOpen.value = false;
        },
    });
};
</script>

<template>
    <Head title="Organization Profile" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 w-full space-y-8">

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white uppercase flex items-center gap-3">
                        <Building2 class="w-8 h-8 text-projector-primary-500" />
                        Organization Profile
                    </h1>
                </div>

                <div class="flex items-center gap-3">
                    <Link
                        v-if="isSuperAdmin"
                        :href="organizationRoutes.create.url()"
                        class="inline-flex items-center bg-projector-primary-600 hover:bg-projector-primary-700 text-white font-bold h-10 px-5 rounded-xl shadow-lg shadow-projector-primary-500/30 active:scale-95 transition-all"
                    >
                        <Plus class="w-4 h-4 mr-2" />
                        <span class="text-[10px] font-black uppercase tracking-widest">New Org</span>
                    </Link>

                    <OrgSwitcher
                        :organizations="organizations"
                        :current-org="currentOrg"
                        @switch="handleOrgSwitch"
                    />
                </div>
            </div>

            <div class="flex items-center gap-4">
                <div v-if="currentOrg.logo_url" class="size-16 rounded-xl border border-gray-200 dark:border-zinc-700 bg-gray-50 dark:bg-zinc-800 overflow-hidden shrink-0">
                    <img :src="currentOrg.logo_url" :alt="currentOrg.name" class="size-full object-contain" />
                </div>
                <div v-else class="size-16 rounded-xl border border-gray-200 dark:border-zinc-700 bg-gray-50 dark:bg-zinc-800 flex items-center justify-center shrink-0">
                    <Building2 class="w-8 h-8 text-gray-300 dark:text-zinc-600" />
                </div>
                <div>
                    <h2 class="text-3xl font-black uppercase tracking-tighter text-gray-900 dark:text-white">{{ currentOrg.name }}</h2>
                    <div class="flex flex-wrap gap-4 text-gray-500 dark:text-zinc-400 text-sm mt-1">
                        <span v-if="currentOrg.website" class="flex items-center gap-1.5">
                            <Globe class="h-3.5 w-3.5" /> {{ currentOrg.website }}
                        </span>
                        <span v-if="currentOrg.email" class="flex items-center gap-1.5">
                            <Mail class="h-3.5 w-3.5" /> {{ currentOrg.email }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div>
                <div class="flex items-center gap-1 border-b border-gray-200 dark:border-zinc-800">
                    <button
                        type="button"
                        @click="activeTab = 'team'"
                        class="flex items-center gap-2 px-4 py-2.5 text-[10px] font-black uppercase tracking-widest transition-colors border-b-2 -mb-px"
                        :class="activeTab === 'team'
                            ? 'border-projector-primary-500 text-projector-primary-600 dark:text-projector-primary-400'
                            : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'"
                    >
                        <Users class="w-3.5 h-3.5" />
                        Team
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'clients'"
                        class="flex items-center gap-2 px-4 py-2.5 text-[10px] font-black uppercase tracking-widest transition-colors border-b-2 -mb-px"
                        :class="activeTab === 'clients'
                            ? 'border-projector-primary-500 text-projector-primary-600 dark:text-projector-primary-400'
                            : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'"
                    >
                        <Briefcase class="w-3.5 h-3.5" />
                        Clients
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'configuration'"
                        class="flex items-center gap-2 px-4 py-2.5 text-[10px] font-black uppercase tracking-widest transition-colors border-b-2 -mb-px"
                        :class="activeTab === 'configuration'
                            ? 'border-projector-primary-500 text-projector-primary-600 dark:text-projector-primary-400'
                            : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'"
                    >
                        <SlidersHorizontal class="w-3.5 h-3.5" />
                        Configuration
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'usage'"
                        class="flex items-center gap-2 px-4 py-2.5 text-[10px] font-black uppercase tracking-widest transition-colors border-b-2 -mb-px"
                        :class="activeTab === 'usage'
                            ? 'border-projector-primary-500 text-projector-primary-600 dark:text-projector-primary-400'
                            : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'"
                    >
                        <Cpu class="w-3.5 h-3.5" />
                        AI Usage
                    </button>
                </div>

                <!-- Team Tab -->
                <div v-if="activeTab === 'team'" class="pt-6 space-y-4">
                    <div v-if="isSuperAdmin || isOrgAdmin" class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <Users class="w-4 h-4" />
                            <span class="font-semibold">
                                {{ effectiveUserCount }}
                                <template v-if="plannedUserCount !== null"> / {{ plannedUserCount }}</template>
                                {{ effectiveUserCount === 1 ? 'user' : 'users' }}
                            </span>
                        </div>
                        <div class="flex gap-3">
                            <button
                                type="button"
                                @click="openAddUser"
                                class="inline-flex items-center bg-projector-primary-600 hover:bg-projector-primary-700 text-white font-bold h-10 px-5 rounded-xl shadow-lg shadow-projector-primary-500/30 active:scale-95 transition-all gap-2"
                            >
                                <Plus class="w-4 h-4" />
                                <span class="text-[10px] font-black uppercase tracking-widest">Add User</span>
                                <span v-if="remainingUsers !== null" class="text-[9px] font-bold bg-white/20 rounded-md px-1.5 py-0.5">
                                    {{ remainingUsers }} left
                                </span>
                            </button>
                            <button
                                type="button"
                                @click="openInviteUser"
                                class="inline-flex items-center bg-projector-primary-600 hover:bg-projector-primary-700 text-white font-bold h-10 px-5 rounded-xl shadow-lg shadow-projector-primary-500/30 active:scale-95 transition-all gap-2"
                            >
                                <UserPlus class="w-4 h-4" />
                                <span class="text-[10px] font-black uppercase tracking-widest">Invite User</span>
                                <span v-if="remainingUsers !== null" class="text-[9px] font-bold bg-white/20 rounded-md px-1.5 py-0.5">
                                    {{ remainingUsers }} left
                                </span>
                            </button>
                        </div>
                    </div>

                    <ResourceSearch
                        :items="sortedOrgUsers"
                        :search-keys="['name', 'email']"
                        @update:filtered="filteredUsers = $event"
                    />

                    <OrgUserTable
                        :users="filteredUsers"
                        :show-admin-toggle="currentOrg.can?.manage_users ?? false"
                        :all-roles="allRoles"
                    />

                    <div v-if="(isSuperAdmin || isOrgAdmin) && invitations.length > 0">
                        <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-4">Pending Invitations</h3>
                        <OrgInvitationTable :invitations="invitations" :organization-id="currentOrg.id" />
                    </div>
                </div>

                <!-- Clients Tab -->
                <div v-if="activeTab === 'clients'" class="pt-6">
                    <ClientList :clients="clients" :project-types="projectTypes" redirect-to="/organizations" />
                </div>

                <!-- Configuration Tab -->
                <div v-if="activeTab === 'configuration'" class="pt-6">
                    <OrganizationForm
                        :organization="currentOrg"
                        @success="() => {}"
                        @cancel="() => {}"
                    />
                </div>

                <!-- AI Usage Tab -->
                <div v-if="activeTab === 'usage'" class="pt-6 space-y-6">
                    <!-- Empty state -->
                    <div v-if="clientUsageRows.length === 0" class="flex flex-col items-center justify-center py-16 rounded-2xl border border-dashed border-slate-200 dark:border-slate-800">
                        <Cpu class="w-10 h-10 text-slate-300 dark:text-slate-600 mb-3" />
                        <p class="text-sm font-bold text-slate-500">No AI usage recorded yet</p>
                        <p class="text-xs text-slate-400 mt-1">Usage will appear here once AI processing runs for this organization.</p>
                    </div>

                    <!-- Breakdown by client / project -->
                    <div v-else class="space-y-4">
                        <div
                            v-for="row in clientUsageRows"
                            :key="row.clientId"
                            class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden"
                        >
                            <!-- Client header -->
                            <div class="flex items-center px-5 py-4 border-b border-slate-100 dark:border-slate-800">
                                <span class="text-sm font-black text-slate-800 dark:text-slate-100 flex-1">{{ row.clientName }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400 text-right">{{ formatDocs(row.documents_processed) }}</span>
                            </div>

                            <!-- Project rows -->
                            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                <div
                                    v-for="project in row.projects"
                                    :key="project.project_id"
                                    class="flex items-center px-5 py-3"
                                >
                                    <span class="text-xs text-slate-600 dark:text-slate-300 pl-3 border-l-2 border-projector-primary-200 dark:border-projector-primary-800 flex-1">
                                        {{ project.projectName }}
                                    </span>
                                    <span class="text-xs text-slate-400 text-right">{{ formatDocs(project.documents_processed) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total row -->
                    <div v-if="clientUsageRows.length > 0" class="flex items-center px-5 py-4 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm">
                        <span class="text-sm font-black text-slate-800 dark:text-slate-100 flex-1">Total</span>
                        <span class="text-sm font-black text-slate-800 dark:text-slate-100">{{ formatDocs(usageTotals.documents_processed) }}</span>
                    </div>
                </div>
            </div>

            <Dialog v-model:open="isInviteModalOpen">
            <DialogContent class="sm:max-w-[400px]">
                <DialogHeader>
                    <DialogTitle>Invite User to {{ currentOrg.name }}</DialogTitle>
                    <DialogDescription>Enter an email address to send an invitation link.</DialogDescription>
                </DialogHeader>
                <form @submit.prevent="submitInvite(currentOrg.id)" class="grid gap-4 pt-2">
                    <div class="grid gap-2">
                        <Label for="invite-email">Email address</Label>
                        <Input
                            id="invite-email"
                            v-model="inviteForm.email"
                            type="email"
                            placeholder="email@example.com"
                            required
                            autofocus
                        />
                        <InputError :message="inviteForm.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="invite-role">Role</Label>
                        <select
                            id="invite-role"
                            v-model="inviteForm.role"
                            class="w-full h-10 rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-[hsl(222_47%_8%)] px-3 text-sm text-gray-900 dark:text-slate-200 focus:ring-4 focus:ring-projector-primary-500/5 transition-all outline-none"
                        >
                            <option value="team-member">Team Member</option>
                            <option value="project-lead">Project Lead</option>
                            <option value="org-admin">Org Admin</option>
                        </select>
                        <InputError :message="inviteForm.errors.role" />
                    </div>
                    <button
                        type="submit"
                        :disabled="inviteForm.processing"
                        class="inline-flex items-center justify-center w-full h-9 px-4 rounded-md bg-primary text-primary-foreground text-sm font-medium shadow hover:bg-primary/90 transition-colors disabled:pointer-events-none disabled:opacity-50"
                    >
                        Send Invitation
                    </button>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isAddUserListOpen">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>New User for {{ currentOrg.name }}</DialogTitle>
                    <DialogDescription>Add a user for this organization.</DialogDescription>
                </DialogHeader>
                <UserList
                    :users="users"
                    :organization-id="currentOrg.id"
                    @user-added="isAddUserListOpen = false"
                />
            </DialogContent>
        </Dialog>



        </div>

        <UpgradeModal
            :open="showUpgradeModal"
            limit-key="users"
            @close="showUpgradeModal = false"
        />

        <Dialog :open="showBillingWarningModal" @update:open="cancelBillingWarning">
            <DialogContent class="sm:max-w-[420px]">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <AlertTriangle class="w-5 h-5 text-amber-500" />
                        Additional User Charge
                    </DialogTitle>
                    <DialogDescription>
                        You've reached your planned user count of {{ plannedUserCount }}.
                        Adding another user will increase your monthly bill.
                        Do you want to continue?
                    </DialogDescription>
                </DialogHeader>
                <div class="flex justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="cancelBillingWarning"
                        class="inline-flex items-center h-9 px-4 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="confirmBillingWarning"
                        class="inline-flex items-center h-9 px-4 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold transition-colors"
                    >
                        Yes, continue
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
