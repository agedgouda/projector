<script setup lang="ts">
import {
    connect,
    disconnect,
} from '@/actions/App/Http/Controllers/OrganizationDropboxController';
import {
    destroy,
    store,
} from '@/actions/App/Http/Controllers/OrganizationDropboxFoldersController';
import { Form, router } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

interface Folder {
    id: string;
    path: string;
}

interface Binding {
    id: string;
    folder_id: string;
    folder_path: string;
    project: { id: string; name: string };
}

interface Project {
    id: string;
    name: string;
}

interface Props {
    organizationId: string;
    dropboxConnected: boolean;
    dropboxAccountName?: string;
    dropboxConfigured: boolean;
    dropboxBindings: Binding[];
    dropboxAvailableFolders: Folder[];
    dropboxProjects: Project[];
    status?: string;
}

const props = defineProps<Props>();

const statusMessages: Record<string, string> = {
    'dropbox-connected': 'Dropbox account connected.',
    'dropbox-disconnected': 'Dropbox account disconnected.',
    'dropbox-connect-failed': "Dropbox didn't complete the connection.",
    'dropbox-not-configured':
        "This server hasn't been configured with Dropbox app credentials yet.",
    'dropbox-folder-bound': 'Folder bound to project.',
    'dropbox-folder-unbound': 'Folder binding removed.',
};

const failureStatuses = ['dropbox-connect-failed', 'dropbox-not-configured'];

// The inline status box below is easy to miss once the redirect lands back on the
// Configuration tab lower down the page — a failure also gets a toast so it's seen immediately.
onMounted(() => {
    if (props.status && failureStatuses.includes(props.status)) {
        toast.error(statusMessages[props.status]);
    }
});

const selectedFolderId = ref<string | undefined>(undefined);
const selectedProjectId = ref<string | undefined>(undefined);
const submitting = ref(false);

function addBinding() {
    const folder = props.dropboxAvailableFolders.find(
        (f) => f.id === selectedFolderId.value,
    );

    if (!folder || !selectedProjectId.value) {
        return;
    }

    submitting.value = true;

    router.post(
        store(props.organizationId).url,
        {
            folder_id: folder.id,
            folder_path: folder.path,
            project_id: selectedProjectId.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
                selectedFolderId.value = undefined;
                selectedProjectId.value = undefined;
            },
        },
    );
}

function removeBinding(binding: Binding) {
    router.delete(destroy([props.organizationId, binding.id]).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div
        class="space-y-4 rounded-lg border border-slate-200 p-6 dark:border-white/10"
    >
        <div class="flex items-center gap-1.5">
            <h3 class="text-base font-medium">Dropbox</h3>

            <TooltipProvider>
                <Tooltip :delay-duration="200">
                    <TooltipTrigger as-child>
                        <Info
                            class="h-3.5 w-3.5 shrink-0 cursor-help text-slate-400"
                        />
                    </TooltipTrigger>
                    <TooltipContent
                        side="right"
                        class="max-w-xs space-y-1.5 bg-slate-900 px-3 py-2 text-xs text-white"
                    >
                        <p class="font-semibold">How to connect</p>
                        <p>
                            Click "Connect Dropbox" below and approve the
                            connection for this organization's Dropbox account.
                            This lets Projector import files dropped into bound
                            Dropbox folders.
                        </p>
                        <p class="pt-1 font-semibold">
                            If the button doesn't work
                        </p>
                        <p>
                            An admin needs to set up a Dropbox app first
                            (<code>DROPBOX_CLIENT_ID</code>,
                            <code>DROPBOX_CLIENT_SECRET</code> in
                            <code>.env</code>). See
                            <code>docs/dropbox-app-setup.md</code> in the repo
                            for step-by-step setup.
                        </p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
        <p class="text-sm text-muted-foreground">
            Connect this organization's Dropbox account to import files dropped
            into bound folders.
        </p>

        <p
            v-if="status && statusMessages[status]"
            :class="[
                'rounded-lg border p-3 text-sm',
                failureStatuses.includes(status)
                    ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300',
            ]"
        >
            {{ statusMessages[status] }}
        </p>

        <div
            v-if="!dropboxConfigured"
            class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300"
        >
            <p class="font-medium">Dropbox integration not configured</p>
            <p class="mt-1">
                An admin needs to create a Dropbox app and set
                <code>DROPBOX_CLIENT_ID</code> and
                <code>DROPBOX_CLIENT_SECRET</code> in <code>.env</code>. See
                <code>docs/dropbox-app-setup.md</code> in the repo for
                step-by-step instructions.
            </p>
        </div>

        <template v-else-if="dropboxConnected">
            <div
                class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 p-4 dark:border-white/10"
            >
                <div class="space-y-0.5">
                    <p
                        class="text-sm font-medium text-slate-900 dark:text-slate-100"
                    >
                        Connected
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ dropboxAccountName }}
                    </p>
                </div>

                <Form
                    v-bind="disconnect.form(props.organizationId)"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="secondary"
                        :disabled="processing"
                        >Disconnect</Button
                    >
                </Form>
            </div>

            <div class="space-y-3">
                <p
                    v-if="dropboxBindings.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No folders are bound yet.
                </p>

                <div
                    v-for="binding in dropboxBindings"
                    :key="binding.id"
                    class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 p-4 dark:border-white/10"
                >
                    <div class="space-y-0.5">
                        <p
                            class="text-sm font-medium text-slate-900 dark:text-slate-100"
                        >
                            {{ binding.folder_path }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ binding.project.name }}
                        </p>
                    </div>

                    <Button
                        type="button"
                        variant="secondary"
                        @click="removeBinding(binding)"
                        >Remove</Button
                    >
                </div>
            </div>

            <div
                class="space-y-3 rounded-lg border border-slate-200 p-4 dark:border-white/10"
            >
                <p
                    class="text-sm font-medium text-slate-900 dark:text-slate-100"
                >
                    Add A Folder
                </p>

                <p
                    v-if="dropboxAvailableFolders.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No unbound top-level folders found in the connected account.
                </p>

                <div v-else class="flex flex-wrap items-center gap-3">
                    <Select
                        :model-value="selectedFolderId"
                        @update:model-value="
                            (v) => (selectedFolderId = v as string)
                        "
                    >
                        <SelectTrigger class="h-9 w-[220px] text-[13px]">
                            <SelectValue placeholder="Select a folder…" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="folder in dropboxAvailableFolders"
                                :key="folder.id"
                                :value="folder.id"
                                >{{ folder.path }}</SelectItem
                            >
                        </SelectContent>
                    </Select>

                    <Select
                        :model-value="selectedProjectId"
                        @update:model-value="
                            (v) => (selectedProjectId = v as string)
                        "
                    >
                        <SelectTrigger class="h-9 w-[220px] text-[13px]">
                            <SelectValue placeholder="Select a project…" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="project in dropboxProjects"
                                :key="project.id"
                                :value="project.id"
                                >{{ project.name }}</SelectItem
                            >
                        </SelectContent>
                    </Select>

                    <Button
                        type="button"
                        :disabled="
                            !selectedFolderId ||
                            !selectedProjectId ||
                            submitting
                        "
                        @click="addBinding"
                        >Add</Button
                    >
                </div>
            </div>
        </template>

        <div
            v-else
            class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 p-4 dark:border-white/10"
        >
            <p class="text-sm text-muted-foreground">Not connected</p>

            <a :href="connect(props.organizationId).url">
                <Button type="button">Connect Dropbox</Button>
            </a>
        </div>
    </div>
</template>
