<script setup lang="ts">
import { connect, disconnect } from '@/actions/App/Http/Controllers/OrganizationSlackController';
import { destroy, store } from '@/actions/App/Http/Controllers/OrganizationSlackChannelsController';
import { Form, router } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';
import { ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

interface Channel {
    id: string;
    name: string;
}

interface Binding {
    id: string;
    channel_id: string;
    channel_name: string;
    project: { id: string; name: string };
}

interface Project {
    id: string;
    name: string;
}

interface Props {
    organizationId: string;
    slackConnected: boolean;
    slackTeamName?: string;
    slackConfigured: boolean;
    slackBindings: Binding[];
    slackAvailableChannels: Channel[];
    slackProjects: Project[];
    status?: string;
}

const props = defineProps<Props>();

const statusMessages: Record<string, string> = {
    'slack-connected': 'Slack workspace connected.',
    'slack-disconnected': 'Slack workspace disconnected.',
    'slack-connect-failed': "Slack didn't complete the connection. Try again below.",
    'slack-not-configured': "This server hasn't been configured with Slack app credentials yet — see the instructions below.",
    'slack-channel-bound': 'Channel bound to project.',
    'slack-channel-unbound': 'Channel binding removed.',
};

const selectedChannelId = ref<string | undefined>(undefined);
const selectedProjectId = ref<string | undefined>(undefined);
const submitting = ref(false);

function addBinding() {
    const channel = props.slackAvailableChannels.find((c) => c.id === selectedChannelId.value);

    if (!channel || !selectedProjectId.value) {
        return;
    }

    submitting.value = true;

    router.post(
        store(props.organizationId).url,
        {
            channel_id: channel.id,
            channel_name: channel.name,
            project_id: selectedProjectId.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
                selectedChannelId.value = undefined;
                selectedProjectId.value = undefined;
            },
        },
    );
}

function removeBinding(binding: Binding) {
    router.delete(destroy([props.organizationId, binding.id]).url, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4 rounded-lg border border-slate-200 p-6 dark:border-white/10">
        <div class="flex items-center gap-1.5">
            <h3 class="text-base font-medium">Slack</h3>

            <TooltipProvider>
                <Tooltip :delay-duration="200">
                    <TooltipTrigger as-child>
                        <Info class="h-3.5 w-3.5 text-slate-400 cursor-help shrink-0" />
                    </TooltipTrigger>
                    <TooltipContent side="right" class="max-w-xs space-y-1.5 bg-slate-900 text-white text-xs px-3 py-2">
                        <p class="font-semibold">How to connect</p>
                        <p>Click "Connect Slack Workspace" below and approve the install for this organization's workspace. This lets Projector create tasks/events and import files from bound Slack channels.</p>
                        <p class="pt-1 font-semibold">If the button doesn't work</p>
                        <p>An admin needs to set up a Slack app first (<code>SLACK_CLIENT_ID</code>, <code>SLACK_CLIENT_SECRET</code>, <code>SLACK_SIGNING_SECRET</code> in <code>.env</code>). See <code>docs/slack-app-setup.md</code> in the repo for step-by-step setup.</p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
        <p class="text-sm text-muted-foreground">Connect this organization's Slack workspace to create tasks and events, and import files, from Slack.</p>

        <p
            v-if="status && statusMessages[status]"
            :class="[
                'text-sm rounded-lg border p-3',
                status === 'slack-not-configured' || status === 'slack-connect-failed'
                    ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300',
            ]"
        >
            {{ statusMessages[status] }}
        </p>

        <div v-if="!slackConfigured" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
            <p class="font-medium">Slack integration not configured</p>
            <p class="mt-1">
                An admin needs to create a Slack app and set <code>SLACK_CLIENT_ID</code>, <code>SLACK_CLIENT_SECRET</code>, and
                <code>SLACK_SIGNING_SECRET</code> in <code>.env</code>. See <code>docs/slack-app-setup.md</code> in the repo for step-by-step instructions.
            </p>
        </div>

        <template v-else-if="slackConnected">
            <div class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 p-4 dark:border-white/10">
                <div class="space-y-0.5">
                    <p class="text-sm font-medium text-slate-900 dark:text-slate-100">Connected</p>
                    <p class="text-sm text-muted-foreground">{{ slackTeamName }}</p>
                </div>

                <Form v-bind="disconnect.form(props.organizationId)" v-slot="{ processing }">
                    <Button type="submit" variant="secondary" :disabled="processing">Disconnect</Button>
                </Form>
            </div>

            <div class="space-y-3">
                <p v-if="slackBindings.length === 0" class="text-sm text-muted-foreground">No channels are bound yet.</p>

                <div
                    v-for="binding in slackBindings"
                    :key="binding.id"
                    class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 p-4 dark:border-white/10"
                >
                    <div class="space-y-0.5">
                        <p class="text-sm font-medium text-slate-900 dark:text-slate-100">#{{ binding.channel_name }}</p>
                        <p class="text-sm text-muted-foreground">{{ binding.project.name }}</p>
                    </div>

                    <Button type="button" variant="secondary" @click="removeBinding(binding)">Remove</Button>
                </div>
            </div>

            <div class="space-y-3 rounded-lg border border-slate-200 p-4 dark:border-white/10">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">Add A Channel</p>

                <p v-if="slackAvailableChannels.length === 0" class="text-sm text-muted-foreground">
                    No unbound channels found — the bot may need to be invited to more channels, or every visible channel is already bound.
                </p>

                <div v-else class="flex flex-wrap items-center gap-3">
                    <Select :model-value="selectedChannelId" @update:model-value="(v) => (selectedChannelId = v as string)">
                        <SelectTrigger class="h-9 w-[220px] text-[13px]">
                            <SelectValue placeholder="Select a channel…" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="channel in slackAvailableChannels" :key="channel.id" :value="channel.id"># {{ channel.name }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select :model-value="selectedProjectId" @update:model-value="(v) => (selectedProjectId = v as string)">
                        <SelectTrigger class="h-9 w-[220px] text-[13px]">
                            <SelectValue placeholder="Select a project…" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="project in slackProjects" :key="project.id" :value="project.id">{{ project.name }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <Button type="button" :disabled="!selectedChannelId || !selectedProjectId || submitting" @click="addBinding">Add</Button>
                </div>
            </div>
        </template>

        <div v-else class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 p-4 dark:border-white/10">
            <p class="text-sm text-muted-foreground">Not connected</p>

            <a :href="connect(props.organizationId).url">
                <Button type="button">Connect Slack Workspace</Button>
            </a>
        </div>
    </div>
</template>
