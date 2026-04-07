<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { resend } from '@/actions/App/Http/Controllers/InvitationController';
import { Link2 } from 'lucide-vue-next';
import { toast } from 'vue-sonner';

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
    <div class="bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 rounded-xl overflow-hidden shadow-sm">
        <div class="grid grid-cols-[1fr_140px_110px_140px] bg-gray-50/50 dark:bg-zinc-800/50 border-b border-gray-200 dark:border-zinc-800">
            <div class="p-4 pl-8 text-[10px] font-black uppercase tracking-widest text-gray-400">Invited Email</div>
            <div class="p-4 text-[10px] font-black uppercase tracking-widest text-gray-400">Role</div>
            <div class="p-4 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Resend</div>
            <div class="p-4 pr-6 text-[10px] font-black uppercase tracking-widest text-gray-400 text-center">Copy Link</div>
        </div>

        <div class="divide-y divide-gray-50 dark:divide-zinc-800/50">
            <div
                v-for="invitation in invitations"
                :key="invitation.id"
                class="grid grid-cols-[1fr_140px_110px_140px] items-center hover:bg-gray-50/30 dark:hover:bg-zinc-800/10 transition-colors"
            >
                <div class="p-4 pl-8 min-w-0">
                    <span class="text-sm font-medium text-gray-800 dark:text-zinc-100 truncate block">{{ invitation.email }}</span>
                </div>
                <div class="p-4 min-w-0">
                    <span class="text-xs font-medium text-gray-500 dark:text-zinc-400 capitalize">{{ invitation.role?.replace('-', ' ') ?? 'Team Member' }}</span>
                </div>

                <div class="px-4 flex justify-center">
                    <Link
                        :href="resend([props.organizationId, invitation.id]).url"
                        method="post"
                        as="button"
                        :preserve-scroll="true"
                        class="inline-flex items-center justify-center h-9 px-3 text-[10px] font-black uppercase tracking-widest whitespace-nowrap rounded-md border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground transition-colors"
                    >
                        Resend
                    </Link>
                </div>

                <div class="px-4 pr-6 flex justify-center">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center h-9 px-3 text-[10px] font-black uppercase tracking-widest whitespace-nowrap rounded-md border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground transition-colors"
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
