<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { ref } from 'vue';
import OrgUserTable from '@/components/user/OrgUserTable.vue';
import OrgSwitcher from '@/components/user/OrgSwitcher.vue';
import { Head, router, Link, usePage, useForm } from '@inertiajs/vue3';
import { Building2, Globe, Mail, Plus, UserPlus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
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


defineProps<{
    organizations: Organization[];
    users: User[];
    currentOrg: Organization;
    allRoles: string[];
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

const isAddUserListOpen = ref(false);
const isInviteModalOpen = ref(false);

const inviteForm = useForm({ email: '' });

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
                    :all-roles="allRoles"
                />
            </div>

            <div v-if="isSuperAdmin || isOrgAdmin" class="flex items-center gap-3">
                <Button
                    variant="outline"
                    @click="isAddUserListOpen = true"
                    class="h-12 px-4 rounded-xl border-dashed border-gray-300 dark:border-zinc-700 hover:border-indigo-500 transition-colors"
                >
                    Add User
                </Button>
                <Button
                    variant="outline"
                    @click="isInviteModalOpen = true"
                    class="h-12 px-4 rounded-xl border-dashed border-gray-300 dark:border-zinc-700 hover:border-indigo-500 transition-colors"
                >
                    <UserPlus class="w-4 h-4 mr-2 text-indigo-500" />
                    <span class="text-[10px] font-black uppercase tracking-widest">Invite User</span>
                </Button>
            </div>

            <div>
                <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 mb-4">AI Drivers</h3>
                <div class="grid grid-cols-3 gap-4">
                    <div class="rounded-xl border border-gray-100 dark:border-zinc-800 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 mb-1">LLM Driver</p>
                        <p class="text-sm font-bold text-gray-800 dark:text-zinc-100">
                            {{ currentOrg.llm_driver || 'System Default' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-100 dark:border-zinc-800 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 mb-1">Vector Driver</p>
                        <p class="text-sm font-bold text-gray-800 dark:text-zinc-100">
                            {{ currentOrg.vector_driver || 'System Default' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-100 dark:border-zinc-800 p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 mb-1">Meeting Provider</p>
                        <p class="text-sm font-bold text-gray-800 dark:text-zinc-100">
                            {{ currentOrg.meeting_provider || 'None' }}
                        </p>
                    </div>
                </div>
            </div>

            <Button
                v-if="isSuperAdmin || isOrgAdmin"
                @click="router.visit(organizationRoutes.edit.url(currentOrg.id))"
                class="h-12 px-4 rounded-xl border-dashed border-gray-300 dark:border-zinc-700 hover:border-indigo-500 transition-colors"
            >
            Edit Organization Information
            </Button>

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
                    <Button type="submit" :disabled="inviteForm.processing" class="w-full">
                        Send Invitation
                    </Button>
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
