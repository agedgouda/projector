<script setup lang="ts">
import IntegrationsController from '@/actions/App/Http/Controllers/Settings/IntegrationsController';
import { edit } from '@/routes/integrations';
import { Form, Head } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Props {
    googleConnected: boolean;
    googleEmail?: string;
    googleConfigured: boolean;
    status?: string;
}

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Integrations',
        href: edit().url,
    },
];

const statusMessages: Record<string, string> = {
    'google-connected': 'Google account connected.',
    'google-disconnected': 'Google account disconnected.',
    'google-connect-failed': "Google didn't grant offline access, so no refresh token came back. Try connecting again — the consent screen must be shown even if you've connected before.",
    'google-not-configured': "This server hasn't been configured with Google OAuth credentials yet — see the instructions below.",
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Integrations" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <header>
                    <div class="flex items-center gap-1.5">
                        <h3 class="text-base font-medium">Google</h3>

                        <TooltipProvider>
                            <Tooltip :delay-duration="200">
                                <TooltipTrigger as-child>
                                    <Info class="h-3.5 w-3.5 text-slate-400 cursor-help shrink-0" />
                                </TooltipTrigger>
                                <TooltipContent side="right" class="max-w-xs space-y-1.5 bg-slate-900 text-white text-xs px-3 py-2">
                                    <p class="font-semibold">How to connect</p>
                                    <p>Click "Connect Google Account" below and sign in. On the consent screen, grant access when prompted — this is what lets Projector create files in your Drive.</p>
                                    <p class="pt-1 font-semibold">If the button doesn't work</p>
                                    <p>An admin needs to set up a Google Cloud OAuth client first (<code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code>, <code>GOOGLE_REDIRECT_URI</code> in <code>.env</code>). See <code>docs/google-drive-export-setup.md</code> in the repo for step-by-step setup.</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                    <p class="text-sm text-muted-foreground">Connect your Google account to export task reports directly to Google Sheets and Google Docs</p>
                </header>

                <p
                    v-if="status && statusMessages[status]"
                    :class="[
                        'text-sm rounded-lg border p-3',
                        status === 'google-not-configured' || status === 'google-connect-failed'
                            ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300',
                    ]"
                >
                    {{ statusMessages[status] }}
                </p>

                <div v-if="!googleConfigured" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
                    <p class="font-medium">Google integration not configured</p>
                    <p class="mt-1">
                        An admin needs to create a Google Cloud OAuth client and set <code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code>, and
                        <code>GOOGLE_REDIRECT_URI</code> in <code>.env</code>. See <code>docs/google-drive-export-setup.md</code> in the repo for step-by-step
                        instructions.
                    </p>
                </div>

                <div v-else-if="googleConnected" class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 dark:border-white/10 p-4">
                    <div class="space-y-0.5">
                        <p class="text-sm font-medium text-slate-900 dark:text-slate-100">Connected</p>
                        <p class="text-sm text-muted-foreground">{{ googleEmail }}</p>
                    </div>

                    <Form v-bind="IntegrationsController.disconnectGoogle.form()" v-slot="{ processing }">
                        <Button type="submit" variant="secondary" :disabled="processing">Disconnect</Button>
                    </Form>
                </div>

                <div v-else class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 dark:border-white/10 p-4">
                    <p class="text-sm text-muted-foreground">Not connected</p>

                    <a :href="IntegrationsController.connectGoogle().url">
                        <Button type="button">Connect Google Account</Button>
                    </a>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
