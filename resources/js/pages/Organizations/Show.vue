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
import { Building2, Globe, Mail, Plus, UserPlus, Users, SlidersHorizontal, Briefcase } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import organizationRoutes from '@/routes/organizations/index';
import {
    Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle
} from "@/components/ui/dialog";
import UserList from '@/components/users/UserList.vue';
import { store as inviteUser } from '@/actions/App/Http/Controllers/InvitationController';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';


const props = defineProps<{
    organizations: Organization[];
    users: User[];
    currentOrg: Organization & {
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

const activeTab = ref<'team' | 'clients' | 'configuration'>('team');
const sortedOrgUsers = computed(() =>
    [...(props.currentOrg.users ?? [])].sort((a, b) => a.name.localeCompare(b.name))
);
const filteredUsers = ref<User[]>(sortedOrgUsers.value);

const isAddUserListOpen = ref(false);
const isInviteModalOpen = ref(false);

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
                        <Building2 class="w-8 h-8 text-indigo-500" />
                        Organization Profile
                    </h1>
                </div>

                <div class="flex items-center gap-3">
                    <Link
                        v-if="isSuperAdmin"
                        :href="organizationRoutes.create.url()"
                        class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-10 px-5 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all"
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

            <!-- Tabs -->
            <div>
                <div class="flex items-center gap-1 border-b border-gray-200 dark:border-zinc-800">
                    <button
                        type="button"
                        @click="activeTab = 'team'"
                        class="flex items-center gap-2 px-4 py-2.5 text-[11px] font-black uppercase tracking-widest transition-colors border-b-2 -mb-px"
                        :class="activeTab === 'team'
                            ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                            : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'"
                    >
                        <Users class="w-3.5 h-3.5" />
                        Team
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'clients'"
                        class="flex items-center gap-2 px-4 py-2.5 text-[11px] font-black uppercase tracking-widest transition-colors border-b-2 -mb-px"
                        :class="activeTab === 'clients'
                            ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                            : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'"
                    >
                        <Briefcase class="w-3.5 h-3.5" />
                        Clients
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'configuration'"
                        class="flex items-center gap-2 px-4 py-2.5 text-[11px] font-black uppercase tracking-widest transition-colors border-b-2 -mb-px"
                        :class="activeTab === 'configuration'
                            ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                            : 'border-transparent text-gray-400 hover:text-gray-600 dark:hover:text-zinc-300'"
                    >
                        <SlidersHorizontal class="w-3.5 h-3.5" />
                        Configuration
                    </button>
                </div>

                <!-- Team Tab -->
                <div v-if="activeTab === 'team'" class="pt-6 space-y-4">
                    <div v-if="isSuperAdmin || isOrgAdmin" class="flex justify-end gap-3">
                        <button
                            type="button"
                            @click="isAddUserListOpen = true"
                            class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-10 px-5 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all"
                        >
                            <Plus class="w-4 h-4 mr-2" />
                            <span class="text-[10px] font-black uppercase tracking-widest">Add User</span>
                        </button>
                        <button
                            type="button"
                            @click="isInviteModalOpen = true"
                            class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-10 px-5 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all"
                        >
                            <UserPlus class="w-4 h-4 mr-2" />
                            <span class="text-[10px] font-black uppercase tracking-widest">Invite User</span>
                        </button>
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
                            class="w-full h-10 rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-950 px-3 text-sm text-gray-900 dark:text-gray-100 focus:ring-4 focus:ring-indigo-500/5 transition-all outline-none"
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
    </AppLayout>
</template>
