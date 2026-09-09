<script setup lang="ts">
import {
    store as inviteUser,
    update as updateInvitation,
} from '@/actions/App/Http/Controllers/InvitationController';
import ClientList from '@/components/clients/ClientList.vue';
import InputError from '@/components/InputError.vue';
import ResourceSearch from '@/components/ResourceSearch.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import UpgradeModal from '@/components/UpgradeModal.vue';
import OrgInvitationTable from '@/components/user/OrgInvitationTable.vue';
import OrgUserTable from '@/components/user/OrgUserTable.vue';
import UserList from '@/components/users/UserList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import organizationRoutes from '@/routes/organizations/index';
import type { AppPageProps, BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Briefcase,
    Building2,
    Cpu,
    Globe,
    Mail,
    Plus,
    SlidersHorizontal,
    UserPlus,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DropboxIntegration from './Partials/DropboxIntegration.vue';
import OrganizationForm from './Partials/OrganizationForm.vue';
import SlackIntegration from './Partials/SlackIntegration.vue';

const props = defineProps<{
    users: User[];
    currentOrg: Organization & {
        logo_url?: string | null;
        pdf_header_url?: string | null;
        pdf_footer_url?: string | null;
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
    usageTotals: { documents_processed: number; cost_usd: number };
    usageByClient: Record<
        string,
        {
            documents_processed: number;
            cost_usd: number;
            projects: {
                project_id: string;
                documents_processed: number;
                cost_usd: number;
            }[];
        }
    >;
    slackConnected: boolean;
    slackTeamName?: string;
    slackConfigured: boolean;
    slackBindings: {
        id: string;
        channel_id: string;
        channel_name: string;
        project: { id: string; name: string };
    }[];
    slackAvailableChannels: { id: string; name: string }[];
    slackProjects: { id: string; name: string }[];
    dropboxConnected: boolean;
    dropboxAccountName?: string;
    dropboxConfigured: boolean;
    dropboxBindings: {
        id: string;
        folder_id: string;
        folder_path: string;
        project: { id: string; name: string };
    }[];
    dropboxProjects: { id: string; name: string }[];
    status?: string;
}>();

const page = usePage<AppPageProps>();

// Helper to check for super-admin role
const isSuperAdmin = page.props.auth.user.roles?.includes('super-admin');
const isOrgAdmin = page.props.auth.user.roles?.includes('org-admin');

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Organization Profile', href: '' },
];

const activeTab = ref<'team' | 'clients' | 'configuration' | 'usage'>('team');

const formatDocs = (n: number) =>
    `${n.toLocaleString()} ${n === 1 ? 'document' : 'documents'}`;

const clientUsageRows = computed(() => {
    return Object.entries(props.usageByClient)
        .map(([clientId, data]) => {
            const client = props.clients.find((c) => c.id === clientId);
            return {
                clientId,
                clientName: client?.company_name ?? 'Unknown Client',
                ...data,
                projects: data.projects.map((p) => {
                    const project = props.clients
                        .flatMap((c) => c.projects ?? [])
                        .find((pr: any) => pr.id === p.project_id);
                    return {
                        ...p,
                        projectName: project?.name ?? 'Unknown Project',
                    };
                }),
            };
        })
        .sort((a, b) => b.cost_usd - a.cost_usd);
});
const sortedOrgUsers = computed(() =>
    [...(props.currentOrg.users ?? [])].sort((a, b) =>
        a.name.localeCompare(b.name),
    ),
);
const filteredUsers = ref<User[]>(sortedOrgUsers.value);

const isAddUserListOpen = ref(false);
const isInviteModalOpen = ref(false);
const showUpgradeModal = ref(false);
const showBillingWarningModal = ref(false);
const pendingUserAction = ref<'add' | 'invite' | null>(null);

const atLimit = computed(
    () => (page.props as any).orgMembership?.at_limit ?? {},
);
const orgMembership = computed(() => (page.props as any).orgMembership ?? null);

const currentUserCount = computed(() => orgMembership.value?.usage?.users ?? 0);
const effectiveUserCount = computed(
    () => currentUserCount.value + props.invitations.length,
);
const plannedUserCount = computed<number | null>(
    () => (props.currentOrg as any).planned_user_count ?? null,
);
const remainingUsers = computed(() =>
    plannedUserCount.value !== null
        ? Math.max(0, plannedUserCount.value - effectiveUserCount.value)
        : null,
);
const isOverPlannedCount = computed(
    () =>
        orgMembership.value?.tier !== 'friends_family' &&
        plannedUserCount.value !== null &&
        effectiveUserCount.value >= plannedUserCount.value,
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
    editingInvitation.value = null;
    inviteForm.reset();
    isInviteModalOpen.value = true;
};

// Reuses the "Invite User" modal/form for editing — pre-filled with the invitation's
// current values, and submitted against the update endpoint instead of store. Unlike a
// brand new invite, an at-limit/over-planned-count org shouldn't block editing an
// invitation that's already counted against that limit.
const editingInvitation = ref<OrganizationInvitation | null>(null);

const openEditInvitation = (invitation: OrganizationInvitation) => {
    editingInvitation.value = invitation;
    inviteForm.reset();
    inviteForm.clearErrors();
    inviteForm.first_name = invitation.first_name ?? '';
    inviteForm.last_name = invitation.last_name ?? '';
    inviteForm.email = invitation.email;
    inviteForm.role = invitation.role ?? 'team-member';
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

const inviteForm = useForm({
    first_name: '',
    last_name: '',
    email: '',
    role: 'team-member',
});

const submitInvite = (orgId: string) => {
    const onSuccess = () => {
        inviteForm.reset();
        editingInvitation.value = null;
        isInviteModalOpen.value = false;
    };

    if (editingInvitation.value) {
        inviteForm.put(
            updateInvitation([orgId, editingInvitation.value.id]).url,
            {
                preserveScroll: true,
                onSuccess,
            },
        );
        return;
    }

    inviteForm.post(inviteUser(orgId).url, {
        preserveScroll: true,
        onSuccess,
    });
};
</script>

<template>
    <Head title="Organization Profile" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-8 p-6">
            <div
                class="flex flex-col items-start justify-between gap-6 md:flex-row md:items-center"
            >
                <div>
                    <h1
                        class="flex items-center gap-3 text-2xl font-black tracking-tight text-gray-900 uppercase dark:text-white"
                    >
                        <Building2 class="h-8 w-8 text-projector-primary-500" />
                        Organization Profile
                    </h1>
                </div>

                <div class="flex items-center gap-3">
                    <Link
                        v-if="isSuperAdmin"
                        :href="organizationRoutes.create.url()"
                        class="inline-flex h-10 items-center rounded-md bg-projector-primary-600 px-5 font-bold text-white transition-all hover:bg-projector-primary-700"
                    >
                        <Plus class="mr-2 h-4 w-4" />
                        <span
                            class="text-[10px] font-black tracking-widest uppercase"
                            >New Org</span
                        >
                    </Link>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <div
                    v-if="currentOrg.logo_url"
                    class="size-16 shrink-0 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-zinc-700"
                >
                    <img
                        :src="currentOrg.logo_url"
                        :alt="currentOrg.name"
                        class="size-full object-contain"
                    />
                </div>
                <div
                    v-else
                    class="flex size-16 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-gray-50 dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <Building2
                        class="h-8 w-8 text-gray-300 dark:text-zinc-600"
                    />
                </div>
                <div>
                    <h2
                        class="text-3xl font-black tracking-tighter text-gray-900 uppercase dark:text-white"
                    >
                        {{ currentOrg.name }}
                    </h2>
                    <div
                        class="mt-1 flex flex-wrap gap-4 text-sm text-gray-500 dark:text-zinc-400"
                    >
                        <span
                            v-if="currentOrg.website"
                            class="flex items-center gap-1.5"
                        >
                            <Globe class="h-3.5 w-3.5" />
                            {{ currentOrg.website }}
                        </span>
                        <span
                            v-if="currentOrg.email"
                            class="flex items-center gap-1.5"
                        >
                            <Mail class="h-3.5 w-3.5" /> {{ currentOrg.email }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div>
                <div
                    class="flex items-center gap-1 border-b border-gray-200 dark:border-zinc-800"
                >
                    <button
                        type="button"
                        @click="activeTab = 'team'"
                        class="-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-[10px] font-black tracking-widest uppercase transition-colors"
                        :class="
                            activeTab === 'team'
                                ? 'border-projector-primary-500 text-projector-primary-600 dark:text-projector-primary-400'
                                : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'
                        "
                    >
                        <Users class="h-3.5 w-3.5" />
                        Team
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'clients'"
                        class="-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-[10px] font-black tracking-widest uppercase transition-colors"
                        :class="
                            activeTab === 'clients'
                                ? 'border-projector-primary-500 text-projector-primary-600 dark:text-projector-primary-400'
                                : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'
                        "
                    >
                        <Briefcase class="h-3.5 w-3.5" />
                        Clients
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'configuration'"
                        class="-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-[10px] font-black tracking-widest uppercase transition-colors"
                        :class="
                            activeTab === 'configuration'
                                ? 'border-projector-primary-500 text-projector-primary-600 dark:text-projector-primary-400'
                                : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'
                        "
                    >
                        <SlidersHorizontal class="h-3.5 w-3.5" />
                        Configuration
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'usage'"
                        class="-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-[10px] font-black tracking-widest uppercase transition-colors"
                        :class="
                            activeTab === 'usage'
                                ? 'border-projector-primary-500 text-projector-primary-600 dark:text-projector-primary-400'
                                : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'
                        "
                    >
                        <Cpu class="h-3.5 w-3.5" />
                        AI Usage
                    </button>
                </div>

                <!-- Team Tab -->
                <div v-if="activeTab === 'team'" class="space-y-4 pt-6">
                    <div
                        v-if="isSuperAdmin || isOrgAdmin"
                        class="flex items-center justify-between"
                    >
                        <div
                            class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400"
                        >
                            <Users class="h-4 w-4" />
                            <span class="font-semibold">
                                {{ effectiveUserCount }}
                                <template v-if="plannedUserCount !== null">
                                    / {{ plannedUserCount }}</template
                                >
                                {{
                                    effectiveUserCount === 1 ? 'user' : 'users'
                                }}
                            </span>
                        </div>
                        <div class="flex gap-3">
                            <button
                                type="button"
                                @click="openAddUser"
                                class="inline-flex h-10 items-center gap-2 rounded-md bg-projector-primary-600 px-5 font-bold text-white transition-all hover:bg-projector-primary-700"
                            >
                                <Plus class="h-4 w-4" />
                                <span
                                    class="text-[10px] font-black tracking-widest uppercase"
                                    >Add User</span
                                >
                                <span
                                    v-if="remainingUsers !== null"
                                    class="rounded-md bg-white/20 px-1.5 py-0.5 text-[9px] font-bold"
                                >
                                    {{ remainingUsers }} left
                                </span>
                            </button>
                            <button
                                type="button"
                                @click="openInviteUser"
                                class="inline-flex h-10 items-center gap-2 rounded-md bg-projector-primary-600 px-5 font-bold text-white transition-all hover:bg-projector-primary-700"
                            >
                                <UserPlus class="h-4 w-4" />
                                <span
                                    class="text-[10px] font-black tracking-widest uppercase"
                                    >Invite User</span
                                >
                                <span
                                    v-if="remainingUsers !== null"
                                    class="rounded-md bg-white/20 px-1.5 py-0.5 text-[9px] font-bold"
                                >
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
                        :show-admin-toggle="
                            currentOrg.can?.manage_users ?? false
                        "
                        :all-roles="allRoles"
                    />

                    <div
                        v-if="
                            (isSuperAdmin || isOrgAdmin) &&
                            invitations.length > 0
                        "
                    >
                        <h3
                            class="mb-4 text-[10px] font-black tracking-[0.2em] text-gray-400 uppercase"
                        >
                            Pending Invitations
                        </h3>
                        <OrgInvitationTable
                            :invitations="invitations"
                            :organization-id="currentOrg.id"
                            @edit="openEditInvitation"
                        />
                    </div>
                </div>

                <!-- Clients Tab -->
                <div v-if="activeTab === 'clients'" class="pt-6">
                    <ClientList
                        :clients="clients"
                        redirect-to="/organizations"
                    />
                </div>

                <!-- Configuration Tab -->
                <div
                    v-if="activeTab === 'configuration'"
                    class="space-y-6 pt-6"
                >
                    <OrganizationForm
                        :organization="currentOrg"
                        @success="() => {}"
                        @cancel="() => {}"
                    />

                    <SlackIntegration
                        :organization-id="currentOrg.id"
                        :slack-connected="slackConnected"
                        :slack-team-name="slackTeamName"
                        :slack-configured="slackConfigured"
                        :slack-bindings="slackBindings"
                        :slack-available-channels="slackAvailableChannels"
                        :slack-projects="slackProjects"
                        :status="status"
                    />

                    <DropboxIntegration
                        :organization-id="currentOrg.id"
                        :dropbox-connected="dropboxConnected"
                        :dropbox-account-name="dropboxAccountName"
                        :dropbox-configured="dropboxConfigured"
                        :dropbox-bindings="dropboxBindings"
                        :dropbox-projects="dropboxProjects"
                        :status="status"
                    />
                </div>

                <!-- AI Usage Tab -->
                <div v-if="activeTab === 'usage'" class="space-y-6 pt-6">
                    <!-- Empty state -->
                    <div
                        v-if="clientUsageRows.length === 0"
                        class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 py-16 dark:border-slate-800"
                    >
                        <Cpu
                            class="mb-3 h-10 w-10 text-slate-300 dark:text-slate-600"
                        />
                        <p class="text-sm font-bold text-slate-500">
                            No AI usage recorded yet
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            Usage will appear here once AI processing runs for
                            this organization.
                        </p>
                    </div>

                    <!-- Breakdown by client / project -->
                    <div v-else class="space-y-6">
                        <div
                            v-for="row in clientUsageRows"
                            :key="row.clientId"
                            class="space-y-0.5"
                        >
                            <!-- Client header -->
                            <div class="flex h-10 items-center px-2">
                                <span
                                    class="flex-1 truncate text-[13px] font-black text-slate-800 dark:text-slate-100"
                                    >{{ row.clientName }}</span
                                >
                                <span class="text-xs text-slate-400">{{
                                    formatDocs(row.documents_processed)
                                }}</span>
                            </div>

                            <!-- Project rows -->
                            <div class="relative pl-7">
                                <div
                                    class="absolute top-0 bottom-0 left-[14px] w-px bg-slate-200 dark:bg-slate-800"
                                ></div>
                                <div
                                    v-for="project in row.projects"
                                    :key="project.project_id"
                                    class="flex h-9 items-center px-2"
                                >
                                    <span
                                        class="flex-1 truncate text-xs text-slate-500 dark:text-slate-400"
                                        >{{ project.projectName }}</span
                                    >
                                    <span class="text-xs text-slate-400">{{
                                        formatDocs(project.documents_processed)
                                    }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total row -->
                    <div
                        v-if="clientUsageRows.length > 0"
                        class="mt-2 flex h-12 items-center border-t border-slate-200 px-2 dark:border-slate-800"
                    >
                        <span
                            class="flex-1 text-sm font-black text-slate-800 dark:text-slate-100"
                            >Total</span
                        >
                        <span
                            class="text-sm font-black text-slate-800 dark:text-slate-100"
                            >{{
                                formatDocs(usageTotals.documents_processed)
                            }}</span
                        >
                    </div>
                </div>
            </div>

            <Dialog v-model:open="isInviteModalOpen">
                <DialogContent class="sm:max-w-[400px]">
                    <DialogHeader>
                        <DialogTitle>{{
                            editingInvitation
                                ? 'Edit Invitation'
                                : `Invite User to ${currentOrg.name}`
                        }}</DialogTitle>
                        <DialogDescription v-if="editingInvitation"
                            >Update their details and resend the invitation
                            link. If that email is already registered, they'll
                            be added to the organization directly
                            instead.</DialogDescription
                        >
                        <DialogDescription v-else
                            >Enter their name and email address to send an
                            invitation link. If that email is already
                            registered, they'll be added to the organization
                            directly instead.</DialogDescription
                        >
                    </DialogHeader>
                    <form
                        @submit.prevent="submitInvite(currentOrg.id)"
                        class="grid gap-4 pt-2"
                    >
                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label for="invite-first-name"
                                    >First name</Label
                                >
                                <Input
                                    id="invite-first-name"
                                    v-model="inviteForm.first_name"
                                    type="text"
                                    placeholder="Jane"
                                    required
                                    autofocus
                                />
                                <InputError
                                    :message="inviteForm.errors.first_name"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="invite-last-name">Last name</Label>
                                <Input
                                    id="invite-last-name"
                                    v-model="inviteForm.last_name"
                                    type="text"
                                    placeholder="Doe"
                                    required
                                />
                                <InputError
                                    :message="inviteForm.errors.last_name"
                                />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="invite-email">Email address</Label>
                            <Input
                                id="invite-email"
                                v-model="inviteForm.email"
                                type="email"
                                placeholder="email@example.com"
                                required
                            />
                            <InputError :message="inviteForm.errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="invite-role">Role</Label>
                            <select
                                id="invite-role"
                                v-model="inviteForm.role"
                                class="h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 transition-all outline-none focus:ring-4 focus:ring-projector-primary-500/5 dark:border-white/10 dark:bg-[hsl(222_47%_8%)] dark:text-slate-200"
                            >
                                <option value="team-member">Team Member</option>
                                <option value="project-lead">
                                    Project Lead
                                </option>
                                <option value="org-admin">Org Admin</option>
                            </select>
                            <InputError :message="inviteForm.errors.role" />
                        </div>
                        <button
                            type="submit"
                            :disabled="inviteForm.processing"
                            class="inline-flex h-9 w-full items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
                        >
                            {{
                                editingInvitation
                                    ? 'Save and Resend'
                                    : 'Send Invitation'
                            }}
                        </button>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog v-model:open="isAddUserListOpen">
                <DialogContent class="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle
                            >New User for {{ currentOrg.name }}</DialogTitle
                        >
                        <DialogDescription
                            >Add a user for this
                            organization.</DialogDescription
                        >
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

        <Dialog
            :open="showBillingWarningModal"
            @update:open="cancelBillingWarning"
        >
            <DialogContent class="sm:max-w-[420px]">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <AlertTriangle class="h-5 w-5 text-amber-500" />
                        Additional User Charge
                    </DialogTitle>
                    <DialogDescription>
                        You've reached your planned user count of
                        {{ plannedUserCount }}. Adding another user will
                        increase your monthly bill. Do you want to continue?
                    </DialogDescription>
                </DialogHeader>
                <div class="flex justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="cancelBillingWarning"
                        class="inline-flex h-9 items-center rounded-lg border border-gray-200 px-4 text-sm font-semibold text-gray-600 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="confirmBillingWarning"
                        class="inline-flex h-9 items-center rounded-lg bg-amber-500 px-4 text-sm font-bold text-white transition-colors hover:bg-amber-600"
                    >
                        Yes, continue
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
