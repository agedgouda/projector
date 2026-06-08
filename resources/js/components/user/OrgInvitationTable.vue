<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { resend } from '@/actions/App/Http/Controllers/InvitationController';
import { Link2 } from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import { FLAT_ROW_HOVER } from '@/lib/flat-ui';

const props = defineProps<{
    invitations: OrganizationInvitation[];
    organizationId: string;
}>();

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
</script>

<template>
    <div>
        <div class="grid grid-cols-[1fr_140px_110px_140px] h-9 px-2 items-center">
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400">Invited Email</div>
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400">Role</div>
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400 text-center">Resend</div>
            <div class="text-[9px] font-black uppercase tracking-widest text-slate-400 text-center">Copy Link</div>
        </div>

        <div class="grid gap-0.5">
            <div
                v-for="invitation in invitations"
                :key="invitation.id"
                :class="['grid grid-cols-[1fr_140px_110px_140px] items-center h-12 px-2 rounded-md transition-colors', FLAT_ROW_HOVER]"
            >
                <div class="min-w-0">
                    <span class="text-[13px] font-medium text-slate-900 dark:text-slate-100 truncate block">{{ invitation.email }}</span>
                </div>
                <div class="min-w-0">
                    <span class="text-[11px] text-slate-400 capitalize">{{ invitation.role?.replace('-', ' ') ?? 'Team Member' }}</span>
                </div>

                <div class="flex justify-center">
                    <Link
                        :href="resend([props.organizationId, invitation.id]).url"
                        method="post"
                        as="button"
                        :preserve-scroll="true"
                        class="inline-flex items-center justify-center h-8 px-3 text-[10px] font-black uppercase tracking-widest whitespace-nowrap rounded-md text-slate-500 hover:text-projector-primary-600 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-950/30 transition-colors"
                    >
                        Resend
                    </Link>
                </div>

                <div class="flex justify-center">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center h-8 px-3 text-[10px] font-black uppercase tracking-widest whitespace-nowrap rounded-md text-slate-500 hover:text-projector-primary-600 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-950/30 transition-colors"
                        @click="copyLink(invitation.token)"
                    >
                        <Link2 class="w-3 h-3 mr-1" />
                        Copy Link
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
