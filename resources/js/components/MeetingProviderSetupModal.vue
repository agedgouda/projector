<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';

const props = defineProps<{
    open: boolean;
    provider: string;
}>();

const emit = defineEmits(['close']);

const titles: Record<string, string> = {
    zoom:         'Zoom Setup Guide',
    teams:        'Microsoft Teams Setup Guide',
    google_meet:  'Google Meet Setup Guide',
    slack:        'Slack Setup Guide',
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('close')">
        <DialogContent class="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ titles[provider] }}</DialogTitle>
                <DialogDescription>
                    Follow these steps to connect your meeting provider and enable transcript capture.
                </DialogDescription>
            </DialogHeader>

            <!-- ── ZOOM ─────────────────────────────────────────────── -->
            <template v-if="provider === 'zoom'">
                <div class="space-y-5 text-sm text-gray-700 dark:text-gray-300">
                    <div class="space-y-3">
                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">1</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Create a Server-to-Server OAuth App</p>
                                <p class="text-xs text-gray-500 mt-0.5">Go to <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">marketplace.zoom.us</span>, sign in as an admin, and navigate to <strong>Develop &gt; Build App</strong>. Choose <strong>Server-to-Server OAuth</strong> and click <strong>Create</strong>.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">2</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Add Required Scopes</p>
                                <p class="text-xs text-gray-500 mt-0.5">Under <strong>Scopes</strong>, add the following:</p>
                                <ul class="mt-1 space-y-1">
                                    <li class="text-xs font-mono bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">cloud_recording:read:admin</li>
                                    <li class="text-xs font-mono bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">meeting:read:admin</li>
                                </ul>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">3</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Activate the App</p>
                                <p class="text-xs text-gray-500 mt-0.5">Click <strong>Activate your app</strong>. The app must be active before credentials will work.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">4</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Enable Cloud Recording Transcripts</p>
                                <p class="text-xs text-gray-500 mt-0.5">In the Zoom Admin portal, go to <strong>Account Management &gt; Account Settings &gt; Recording</strong> and enable <strong>Audio transcript</strong>.</p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-800 pt-4 space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Field Reference</p>
                        <div class="rounded-xl border border-gray-100 dark:border-gray-800 overflow-hidden">
                            <div class="grid grid-cols-2 bg-gray-50 dark:bg-gray-900 px-4 py-2 text-[9px] font-black uppercase tracking-widest text-gray-400">
                                <span>Projector Field</span>
                                <span>Where to Find It</span>
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Account ID</span>
                                    <span class="text-gray-500">App credentials page → <strong>Account ID</strong></span>
                                </div>
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Client ID</span>
                                    <span class="text-gray-500">App credentials page → <strong>Client ID</strong></span>
                                </div>
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Client Secret</span>
                                    <span class="text-gray-500">App credentials page → <strong>Client Secret</strong></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── MICROSOFT TEAMS ──────────────────────────────────── -->
            <template v-else-if="provider === 'teams'">
                <div class="space-y-5 text-sm text-gray-700 dark:text-gray-300">
                    <div class="space-y-3">
                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">1</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Register an Application in Azure</p>
                                <p class="text-xs text-gray-500 mt-0.5">Sign in to <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">portal.azure.com</span> as an admin. Go to <strong>Azure Active Directory &gt; App registrations &gt; New registration</strong>. Give it a name and click <strong>Register</strong>.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">2</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Add API Permissions</p>
                                <p class="text-xs text-gray-500 mt-0.5">Go to <strong>API permissions &gt; Add a permission &gt; Microsoft Graph &gt; Application permissions</strong> and add:</p>
                                <ul class="mt-1 space-y-1">
                                    <li class="text-xs font-mono bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">OnlineMeetings.Read.All</li>
                                    <li class="text-xs font-mono bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">CallRecords.Read.All</li>
                                </ul>
                                <p class="text-xs text-gray-500 mt-1">Then click <strong>Grant admin consent</strong> for your directory.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">3</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Create a Client Secret</p>
                                <p class="text-xs text-gray-500 mt-0.5">Go to <strong>Certificates &amp; secrets &gt; New client secret</strong>. Set an expiry and click <strong>Add</strong>. Copy the secret <strong>Value</strong> immediately — it is only shown once.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">4</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Enable Transcription in Teams Admin</p>
                                <p class="text-xs text-gray-500 mt-0.5">In the <strong>Teams Admin Center</strong>, go to <strong>Meetings &gt; Meeting policies</strong>, select the relevant policy, and enable <strong>Transcription</strong>.</p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-800 pt-4 space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Field Reference</p>
                        <div class="rounded-xl border border-gray-100 dark:border-gray-800 overflow-hidden">
                            <div class="grid grid-cols-2 bg-gray-50 dark:bg-gray-900 px-4 py-2 text-[9px] font-black uppercase tracking-widest text-gray-400">
                                <span>Projector Field</span>
                                <span>Where to Find It</span>
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Tenant ID</span>
                                    <span class="text-gray-500">App registration overview → <strong>Directory (tenant) ID</strong></span>
                                </div>
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Client ID</span>
                                    <span class="text-gray-500">App registration overview → <strong>Application (client) ID</strong></span>
                                </div>
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Client Secret</span>
                                    <span class="text-gray-500">Certificates &amp; secrets → secret <strong>Value</strong></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── GOOGLE MEET ──────────────────────────────────────── -->
            <template v-else-if="provider === 'google_meet'">
                <div class="space-y-5 text-sm text-gray-700 dark:text-gray-300">
                    <div class="space-y-3">
                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">1</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Enable the Google Meet API</p>
                                <p class="text-xs text-gray-500 mt-0.5">In <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">console.cloud.google.com</span>, go to <strong>APIs &amp; Services &gt; Library</strong>, search for <strong>Google Meet API</strong>, and click <strong>Enable</strong>.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">2</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Enable Transcription in Workspace Admin</p>
                                <p class="text-xs text-gray-500 mt-0.5">In <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">admin.google.com</span>, go to <strong>Apps &gt; Google Workspace &gt; Google Meet &gt; Meet video settings</strong> and enable <strong>Transcription</strong>. Requires Business Standard or higher.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">3</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Create a Service Account</p>
                                <p class="text-xs text-gray-500 mt-0.5">In the Cloud Console, go to <strong>IAM &amp; Admin &gt; Service Accounts &gt; Create Service Account</strong>. After creating it, go to the <strong>Keys</strong> tab, click <strong>Add Key &gt; JSON</strong>, and download the file.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">4</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Enable Domain-Wide Delegation</p>
                                <p class="text-xs text-gray-500 mt-0.5">In the Admin Console, go to <strong>Security &gt; API controls &gt; Domain Wide Delegation &gt; Add new</strong>. Enter the service account's <strong>Client ID</strong> and add these scopes:</p>
                                <ul class="mt-1 space-y-1">
                                    <li class="text-xs font-mono bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">https://www.googleapis.com/auth/meetings.space.readonly</li>
                                    <li class="text-xs font-mono bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">https://www.googleapis.com/auth/meetings.space.created</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-800 pt-4 space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Field Reference</p>
                        <div class="rounded-xl border border-gray-100 dark:border-gray-800 overflow-hidden">
                            <div class="grid grid-cols-2 bg-gray-50 dark:bg-gray-900 px-4 py-2 text-[9px] font-black uppercase tracking-widest text-gray-400">
                                <span>Projector Field</span>
                                <span>Where to Find It</span>
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Service Account Email</span>
                                    <span class="text-gray-500">JSON key file → <strong>client_email</strong></span>
                                </div>
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Private Key (PEM)</span>
                                    <span class="text-gray-500">JSON key file → <strong>private_key</strong> (paste full block)</span>
                                </div>
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Impersonate Email</span>
                                    <span class="text-gray-500">A Workspace user the service account will act as</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── SLACK ────────────────────────────────────────────── -->
            <template v-else-if="provider === 'slack'">
                <div class="space-y-5 text-sm text-gray-700 dark:text-gray-300">
                    <div class="space-y-3">
                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">1</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Create a Slack App</p>
                                <p class="text-xs text-gray-500 mt-0.5">Go to <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">api.slack.com/apps</span> and click <strong>Create New App</strong>. Choose <strong>From scratch</strong>, give it a name, and select your workspace.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">2</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Add Bot Token Scopes</p>
                                <p class="text-xs text-gray-500 mt-0.5">Go to <strong>OAuth &amp; Permissions</strong>, scroll to <strong>Scopes &gt; Bot Token Scopes</strong>, and add:</p>
                                <ul class="mt-1 space-y-1">
                                    <li class="text-xs font-mono bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">files:read</li>
                                </ul>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">3</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Install the App to Your Workspace</p>
                                <p class="text-xs text-gray-500 mt-0.5">At the top of <strong>OAuth &amp; Permissions</strong>, click <strong>Install to Workspace</strong> and approve the requested permissions.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">4</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Create a Transcripts Channel</p>
                                <p class="text-xs text-gray-500 mt-0.5">Create a shared channel (e.g. <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">#meeting-transcripts</span>) and type <span class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">/invite @YourAppName</span> in it. The bot can only see files in channels it has been invited to.</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full bg-projector-primary-100 dark:bg-projector-primary-500/20 text-projector-primary-600 dark:text-projector-primary-400 flex items-center justify-center text-[10px] font-black">5</span>
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">Record and Share Your Huddles</p>
                                <p class="text-xs text-gray-500 mt-0.5">Slack does not save or transcribe a Huddle automatically. While in the Huddle, click <strong>Record</strong>. When the recording ends, Slack posts the Clip (with an automatic transcript) to the channel the Huddle was started from — share or forward that Clip into your transcripts channel so the bot can see it and it appears in the import list below.</p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-800 pt-4 space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">Field Reference</p>
                        <div class="rounded-xl border border-gray-100 dark:border-gray-800 overflow-hidden">
                            <div class="grid grid-cols-2 bg-gray-50 dark:bg-gray-900 px-4 py-2 text-[9px] font-black uppercase tracking-widest text-gray-400">
                                <span>Projector Field</span>
                                <span>Where to Find It</span>
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                <div class="grid grid-cols-2 px-4 py-2.5 text-xs">
                                    <span class="font-bold text-gray-700 dark:text-gray-200">Bot User OAuth Token</span>
                                    <span class="text-gray-500">OAuth &amp; Permissions page → <strong>Bot User OAuth Token</strong> (starts with <span class="font-mono">xoxb-</span>)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
