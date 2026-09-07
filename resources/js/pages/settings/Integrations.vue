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

interface SlackIdentity {
    id: string;
    slack_username: string | null;
    team_name: string;
}

interface Props {
    googleConnected: boolean;
    googleEmail?: string;
    googleConfigured: boolean;
    slackConfigured: boolean;
    slackIdentities: SlackIdentity[];
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
    'google-scope-missing': "Google didn't grant Drive access for this connection, so exports would fail. Try connecting again and make sure you approve access when Google's consent screen asks for it.",
    'google-not-configured': "This server hasn't been configured with Google OAuth credentials yet — see the instructions below.",
    'slack-connected': 'Slack account connected.',
    'slack-disconnected': 'Slack account disconnected.',
    'slack-connect-failed': "Slack didn't complete the connection. Try again below.",
    'slack-not-configured': "This server hasn't been configured with Slack app credentials yet — see the instructions below.",
    'slack-team-not-connected': "That Slack workspace isn't connected to any organization you belong to yet — an org-admin needs to connect it first.",
    'slack-identity-taken': 'That Slack account is already linked to a different Projector user.',
};

const amberStatuses = new Set([
    'google-not-configured',
    'google-connect-failed',
    'google-scope-missing',
    'slack-not-configured',
    'slack-connect-failed',
    'slack-team-not-connected',
    'slack-identity-taken',
]);
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
                        amberStatuses.has(status)
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

            <div class="flex flex-col space-y-6">
                <header>
                    <div class="flex items-center gap-1.5">
                        <h3 class="text-base font-medium">Slack</h3>

                        <TooltipProvider>
                            <Tooltip :delay-duration="200">
                                <TooltipTrigger as-child>
                                    <Info class="h-3.5 w-3.5 text-slate-400 cursor-help shrink-0" />
                                </TooltipTrigger>
                                <TooltipContent side="right" class="max-w-xs space-y-1.5 bg-slate-900 text-white text-xs px-3 py-2">
                                    <p class="font-semibold">How to connect</p>
                                    <p>Click "Connect Slack Account" below and sign in. This links your Slack identity so tasks/events you create from Slack are attributed to your account — it's separate from an org-admin connecting the workspace itself.</p>
                                    <p class="pt-1 font-semibold">If it says the workspace isn't connected</p>
                                    <p>An org-admin needs to connect that Slack workspace first, from the organization's own settings page.</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Link your Slack account so tasks and events you create from Slack are attributed to you. You can link one identity per Slack
                        workspace you use across your organizations.
                    </p>
                </header>

                <div v-if="!slackConfigured" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
                    <p class="font-medium">Slack integration not configured</p>
                    <p class="mt-1">
                        An admin needs to create a Slack app and set <code>SLACK_CLIENT_ID</code>, <code>SLACK_CLIENT_SECRET</code>, and
                        <code>SLACK_SIGNING_SECRET</code> in <code>.env</code>. See <code>docs/slack-app-setup.md</code> in the repo for step-by-step
                        instructions.
                    </p>
                </div>

                <template v-else>
                    <p v-if="slackIdentities.length === 0" class="text-sm text-muted-foreground">No Slack accounts linked yet.</p>

                    <div
                        v-for="identity in slackIdentities"
                        :key="identity.id"
                        class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 dark:border-white/10 p-4"
                    >
                        <div class="space-y-0.5">
                            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ identity.slack_username ?? 'Connected' }}</p>
                            <p class="text-sm text-muted-foreground">{{ identity.team_name }}</p>
                        </div>

                        <Form v-bind="IntegrationsController.disconnectSlack.form(identity.id)" v-slot="{ processing }">
                            <Button type="submit" variant="secondary" :disabled="processing">Disconnect</Button>
                        </Form>
                    </div>

                    <div>
                        <a :href="IntegrationsController.connectSlack().url">
                            <Button type="button" :variant="slackIdentities.length === 0 ? 'default' : 'secondary'">Connect Slack Account</Button>
                        </a>
                    </div>
                </template>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
