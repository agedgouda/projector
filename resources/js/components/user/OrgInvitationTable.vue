<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { resend, destroy } from '@/actions/App/Http/Controllers/InvitationController';
import { Link2, Pencil, Trash2 } from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import { ref } from 'vue';
import UserInfo from '@/components/UserInfo.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';

const props = defineProps<{
    invitations: OrganizationInvitation[];
    organizationId: string;
}>();

const emit = defineEmits<{
    (e: 'edit', invitation: OrganizationInvitation): void;
}>();

// Older invitations (sent before name capture existed) have no first_name/last_name —
// falls back to the plain email-only display for those instead of showing a blank name line.
// has_password: false unconditionally dims the avatar (see UserInfo.vue) — an invitation
// is never a real account yet, regardless of how long it's been pending.
const invitedUser = (invitation: OrganizationInvitation) => ({
    first_name: invitation.first_name,
    last_name: invitation.last_name,
    name: [invitation.first_name, invitation.last_name].filter(Boolean).join(' '),
    email: invitation.email,
    avatar: null,
    has_password: false,
});

const emailStatusBadges: Record<InvitationEmailStatus, { label: string; tone: 'neutral' | 'warning' | 'success' | 'danger' }> = {
    sent: { label: 'Sent', tone: 'neutral' },
    delivery_delayed: { label: 'Delayed', tone: 'warning' },
    delivered: { label: 'Delivered', tone: 'success' },
    opened: { label: 'Opened', tone: 'success' },
    bounced: { label: 'Bounced', tone: 'danger' },
    failed: { label: 'Failed', tone: 'danger' },
    suppressed: { label: 'Suppressed', tone: 'danger' },
    complained: { label: 'Marked Spam', tone: 'danger' },
};

const badgeToneClasses = {
    neutral: 'bg-slate-100 text-slate-600 dark:bg-zinc-800 dark:text-zinc-300',
    warning: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
    success: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400',
    danger: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400',
};

const emailStatusTitle = (invitation: OrganizationInvitation) =>
    [
        invitation.email_status_detail,
        invitation.email_status_at ? new Date(invitation.email_status_at).toLocaleString() : null,
    ]
        .filter(Boolean)
        .join(' — ');

const copyLink = (token: string) => {
    const url = `${window.location.origin}/invite/${token}`;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url);
    } else {
        const el = document.createElement('textarea');
        el.value = url;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    toast.success('Invitation Link Copied');
};

const pendingDeleteInvitation = ref<OrganizationInvitation | null>(null);
const isDeleting = ref(false);

const confirmDelete = (invitation: OrganizationInvitation) => {
    pendingDeleteInvitation.value = invitation;
};

const cancelDelete = () => {
    pendingDeleteInvitation.value = null;
};

const executeDelete = () => {
    if (!pendingDeleteInvitation.value) return;

    isDeleting.value = true;

    router.delete(destroy([props.organizationId, pendingDeleteInvitation.value.id]).url, {
        preserveScroll: true,
        onSuccess: () => { pendingDeleteInvitation.value = null; },
        onFinish: () => { isDeleting.value = false; },
    });
};
</script>

<template>
    <div>
        <div class="grid grid-cols-[1fr_140px_110px_110px_140px_90px_90px] h-9 px-2 items-center">
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400">Invited User</div>
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400">Role</div>
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400 text-center">Email</div>
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400 text-center">Resend</div>
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400 text-center">Invitation</div>
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400 text-center">Edit</div>
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400 text-center">Delete</div>
        </div>

        <div class="grid gap-0.5">
            <div
                v-for="invitation in invitations"
                :key="invitation.id"
                class="grid grid-cols-[1fr_140px_110px_110px_140px_90px_90px] items-center h-12 px-2 rounded-md transition-colors"
            >
                <div class="min-w-0">
                    <UserInfo
                        v-if="invitation.first_name && invitation.last_name"
                        :user="invitedUser(invitation)"
                        :show-email="true"
                        compact
                    />
                    <span v-else class="text-[13px] font-medium text-slate-900 dark:text-slate-100 truncate block">{{ invitation.email }}</span>
                </div>
                <div class="min-w-0">
                    <span class="text-[11px] text-slate-400 capitalize">{{ invitation.role?.replace('-', ' ') ?? 'Team Member' }}</span>
                </div>

                <div class="flex justify-center">
                    <span
                        v-if="invitation.email_status && emailStatusBadges[invitation.email_status]"
                        :class="badgeToneClasses[emailStatusBadges[invitation.email_status].tone]"
                        :title="emailStatusTitle(invitation)"
                        class="inline-flex items-center h-6 px-2 rounded text-[9px] font-black uppercase tracking-widest whitespace-nowrap"
                    >
                        {{ emailStatusBadges[invitation.email_status].label }}
                    </span>
                    <span v-else class="text-[11px] text-slate-300 dark:text-zinc-600">—</span>
                </div>

                <div class="flex justify-center">
                    <Link
                        :href="resend([props.organizationId, invitation.id]).url"
                        method="post"
                        as="button"
                        :preserve-scroll="true"
                        class="inline-flex items-center justify-center h-8 px-3 text-[10px] font-black uppercase tracking-widest whitespace-nowrap rounded-md border border-projector-primary-200 text-projector-primary-700 hover:bg-projector-primary-50 dark:border-projector-primary-900/50 dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30 transition-colors"
                    >
                        Resend
                    </Link>
                </div>

                <div class="flex justify-center">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center h-8 px-3 text-[10px] font-black uppercase tracking-widest whitespace-nowrap rounded-md border border-projector-primary-200 text-projector-primary-700 hover:bg-projector-primary-50 dark:border-projector-primary-900/50 dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30 transition-colors"
                        @click="copyLink(invitation.token)"
                    >
                        <Link2 class="w-3 h-3 mr-1" />
                        Copy Link
                    </button>
                </div>

                <div class="flex justify-center">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center h-8 px-3 text-[10px] font-black uppercase tracking-widest whitespace-nowrap rounded-md border border-projector-primary-200 text-projector-primary-700 hover:bg-projector-primary-50 dark:border-projector-primary-900/50 dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30 transition-colors"
                        @click="emit('edit', invitation)"
                    >
                        <Pencil class="w-3 h-3" />
                    </button>
                </div>

                <div class="flex justify-center">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center h-8 px-3 text-[10px] font-black uppercase tracking-widest whitespace-nowrap rounded-md border border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900/50 dark:text-red-400 dark:hover:bg-red-950/30 transition-colors"
                        @click="confirmDelete(invitation)"
                    >
                        <Trash2 class="w-3 h-3" />
                    </button>
                </div>
            </div>
        </div>

        <ConfirmDeleteModal
            :open="!!pendingDeleteInvitation"
            title="Revoke Invitation"
            :description="`This will permanently revoke the invitation for ${pendingDeleteInvitation?.email}. They won't be able to use their invitation link to join anymore.`"
            confirm-label="Revoke Invitation"
            :loading="isDeleting"
            @close="cancelDelete"
            @confirm="executeDelete"
        />
    </div>
</template>
