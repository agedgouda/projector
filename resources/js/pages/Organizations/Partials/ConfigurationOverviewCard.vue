<script setup lang="ts">
import {
    connect as connectDropbox,
    disconnect as disconnectDropbox,
} from '@/actions/App/Http/Controllers/OrganizationDropboxController';
import {
    destroy as destroyDropboxFolder,
    store as storeDropboxFolder,
} from '@/actions/App/Http/Controllers/OrganizationDropboxFoldersController';
import {
    destroy as destroySlackChannel,
    store as storeSlackChannel,
} from '@/actions/App/Http/Controllers/OrganizationSlackChannelsController';
import {
    connect as connectSlack,
    disconnect as disconnectSlack,
} from '@/actions/App/Http/Controllers/OrganizationSlackController';
import LogoUpload from '@/components/LogoUpload.vue';
import MeetingProviderSetupModal from '@/components/MeetingProviderSetupModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    LLM_DRIVERS,
    MEETING_PROVIDERS,
    VECTOR_DRIVERS,
    llmDriverLabel,
    meetingProviderLabel,
    vectorDriverLabel,
} from '@/lib/constants';
import organizationRoutes from '@/routes/organizations/index';
import organizationPdfBrandingRoutes from '@/routes/organizations/pdf-branding/index';
import { Form, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, HelpCircle } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

interface AiConfigForm {
    model: string;
    host: string;
    has_key: boolean;
}

// Matches what Organization::meetingConfigForForm() actually sends — raw secrets
// (client_secret/private_key/bot_token) never round-trip to the frontend, only whether
// one is already saved.
interface MeetingConfigForm {
    account_id: string;
    tenant_id: string;
    client_id: string;
    service_account_email: string;
    impersonate_email: string;
    has_client_secret: boolean;
    has_private_key: boolean;
    has_bot_token: boolean;
}

interface Channel {
    id: string;
    name: string;
}

interface SlackBinding {
    id: string;
    channel_id: string;
    channel_name: string;
    project: { id: string; name: string };
}

interface Folder {
    id: string;
    path: string;
}

interface DropboxBinding {
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
    organization: Organization & {
        llm_config_form?: AiConfigForm;
        vector_config_form?: AiConfigForm;
        meeting_config_form?: MeetingConfigForm;
        pdf_header_url?: string | null;
        pdf_footer_url?: string | null;
    };
    slackConnected: boolean;
    slackTeamName?: string;
    slackConfigured: boolean;
    slackBindings: SlackBinding[];
    slackAvailableChannels: Channel[];
    slackProjects: Project[];
    dropboxConnected: boolean;
    dropboxAccountName?: string;
    dropboxConfigured: boolean;
    dropboxBindings: DropboxBinding[];
    dropboxAvailableFolders: Folder[];
    dropboxProjects: Project[];
    status?: string;
}

const props = defineProps<Props>();

// reka-ui's <SelectItem> rejects an empty-string value (used to mean "clear the
// selection"), but '' is exactly what LLM_DRIVERS/VECTOR_DRIVERS/MEETING_PROVIDERS use
// for "System Default"/"None". Swap in this sentinel only at the Select boundary — the
// underlying form fields keep using '' everywhere else.
const DEFAULT_OPTION_VALUE = '__default__';
const toSelectValue = (value: string) => value || DEFAULT_OPTION_VALUE;
const fromSelectValue = (value: string) =>
    value === DEFAULT_OPTION_VALUE ? '' : value;

// Only one section's sub-level details are shown at a time, directly in the card —
// there's no more "Configure" button/dialog for these, so this is the sole way in.
type Section = 'llm' | 'vector' | 'meeting' | 'slack' | 'dropbox' | 'branding';
const openSection = ref<Section | null>(null);
const toggleSection = (section: Section) => {
    openSection.value = openSection.value === section ? null : section;
};

const statusMessages: Record<string, string> = {
    'slack-connected': 'Slack workspace connected.',
    'slack-disconnected': 'Slack workspace disconnected.',
    'slack-connect-failed': "Slack didn't complete the connection.",
    'slack-not-configured':
        "This server hasn't been configured with Slack app credentials yet.",
    'slack-channel-bound': 'Channel bound to project.',
    'slack-channel-unbound': 'Channel binding removed.',
    'dropbox-connected': 'Dropbox account connected.',
    'dropbox-disconnected': 'Dropbox account disconnected.',
    'dropbox-connect-failed': "Dropbox didn't complete the connection.",
    'dropbox-not-configured':
        "This server hasn't been configured with Dropbox app credentials yet.",
    'dropbox-folder-bound': 'Folder bound to project.',
    'dropbox-folder-unbound': 'Folder binding removed.',
};

const failureStatuses = [
    'slack-connect-failed',
    'slack-not-configured',
    'dropbox-connect-failed',
    'dropbox-not-configured',
];

onMounted(() => {
    if (props.status && statusMessages[props.status]) {
        const message = statusMessages[props.status];
        if (failureStatuses.includes(props.status)) {
            toast.error(message);
        } else {
            toast.success(message);
        }
    }
});

const dueDatesForm = useForm({
    uses_external_due_dates:
        props.organization.uses_external_due_dates ?? false,
});

const saveDueDates = () => {
    dueDatesForm.patch(organizationRoutes.update.url(props.organization.id), {
        preserveScroll: true,
        onError: () => {
            dueDatesForm.uses_external_due_dates =
                !dueDatesForm.uses_external_due_dates;
            toast.error('Could not update setting');
        },
    });
};

// LLM Driver
const llmDriverForm = useForm({
    llm_driver: props.organization.llm_driver || '',
    llm_config: {
        key: '',
        model: props.organization.llm_config_form?.model || '',
        host: props.organization.llm_config_form?.host || '',
    },
});

const saveLlmDriver = () => {
    llmDriverForm.patch(organizationRoutes.update.url(props.organization.id), {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('LLM driver updated');
            openSection.value = null;
        },
        onError: () => toast.error('Could not update LLM driver'),
    });
};

// Reverting to "System Default" needs no sub-fields, so it saves right away. Any other
// driver needs a model (and maybe a key/host), so open the panel instead of saving blind.
const onLlmDriverChange = () => {
    if (llmDriverForm.llm_driver) {
        openSection.value = 'llm';
    } else {
        saveLlmDriver();
    }
};

const llmUsesApiKey = computed(() =>
    ['openai', 'gemini', 'claude'].includes(llmDriverForm.llm_driver),
);
const llmUsesHost = computed(() => llmDriverForm.llm_driver === 'ollama');
const llmKeyPlaceholder = computed(() =>
    props.organization.llm_config_form?.has_key
        ? 'Leave blank to keep existing key'
        : 'Enter API key',
);
const llmDefaultModelPlaceholder = computed((): string => {
    const defaults: Record<string, string> = {
        openai: 'gpt-4o-mini',
        gemini: 'gemini-2.0-flash',
        claude: 'claude-sonnet-4-6',
        ollama: 'deepseek-r1:8b',
    };
    return defaults[llmDriverForm.llm_driver] ?? '';
});

// Embeddings / Vector Driver
const vectorDriverForm = useForm({
    vector_driver: props.organization.vector_driver || '',
    vector_config: {
        key: '',
        model: props.organization.vector_config_form?.model || '',
        host: props.organization.vector_config_form?.host || '',
    },
});

const saveVectorDriver = () => {
    vectorDriverForm.patch(
        organizationRoutes.update.url(props.organization.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Embeddings driver updated');
                openSection.value = null;
            },
            onError: () => toast.error('Could not update embeddings driver'),
        },
    );
};

const vectorShowsConfig = computed(
    () =>
        !!vectorDriverForm.vector_driver &&
        vectorDriverForm.vector_driver !== 'same',
);

const onVectorDriverChange = () => {
    if (vectorShowsConfig.value) {
        openSection.value = 'vector';
    } else {
        saveVectorDriver();
    }
};

const vectorSameAsLlmDisabled = computed(
    () =>
        !props.organization.llm_driver ||
        props.organization.llm_driver === 'claude',
);
const vectorUsesApiKey = computed(() =>
    ['openai', 'gemini'].includes(vectorDriverForm.vector_driver),
);
const vectorUsesHost = computed(
    () => vectorDriverForm.vector_driver === 'ollama',
);
const vectorKeyPlaceholder = computed(() =>
    props.organization.vector_config_form?.has_key
        ? 'Leave blank to keep existing key'
        : 'Enter API key',
);
const vectorDefaultModelPlaceholder = computed((): string => {
    const defaults: Record<string, string> = {
        openai: 'text-embedding-3-small',
        gemini: 'text-embedding-004',
        ollama: 'nomic-embed-text',
    };
    return defaults[vectorDriverForm.vector_driver] ?? '';
});

// Meeting Provider
const meetingProviderForm = useForm({
    meeting_provider: props.organization.meeting_provider || '',
    meeting_config: {
        account_id: props.organization.meeting_config_form?.account_id || '',
        tenant_id: props.organization.meeting_config_form?.tenant_id || '',
        client_id: props.organization.meeting_config_form?.client_id || '',
        client_secret: '',
        service_account_email:
            props.organization.meeting_config_form?.service_account_email || '',
        impersonate_email:
            props.organization.meeting_config_form?.impersonate_email || '',
        private_key: '',
        bot_token: '',
    },
});

const saveMeetingProvider = () => {
    meetingProviderForm.patch(
        organizationRoutes.update.url(props.organization.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Meeting provider updated');
                openSection.value = null;
            },
            onError: () => toast.error('Could not update meeting provider'),
        },
    );
};

const onMeetingProviderChange = () => {
    if (meetingProviderForm.meeting_provider) {
        openSection.value = 'meeting';
    } else {
        saveMeetingProvider();
    }
};

const isMeetingSetupGuideOpen = ref(false);
const isZoom = computed(() => meetingProviderForm.meeting_provider === 'zoom');
const isTeams = computed(
    () => meetingProviderForm.meeting_provider === 'teams',
);
const isGoogleMeet = computed(
    () => meetingProviderForm.meeting_provider === 'google_meet',
);
const isSlackMeeting = computed(
    () => meetingProviderForm.meeting_provider === 'slack',
);
const meetingClientSecretPlaceholder = computed(() =>
    props.organization.meeting_config_form?.has_client_secret
        ? 'Leave blank to keep existing secret'
        : 'Enter client secret',
);
const meetingPrivateKeyPlaceholder = computed(() =>
    props.organization.meeting_config_form?.has_private_key
        ? 'Leave blank to keep existing private key'
        : 'Paste PEM private key here',
);
const meetingBotTokenPlaceholder = computed(() =>
    props.organization.meeting_config_form?.has_bot_token
        ? 'Leave blank to keep existing bot token'
        : 'xoxb-...',
);

// Slack
const selectedSlackChannelId = ref<string | undefined>(undefined);
const selectedSlackProjectId = ref<string | undefined>(undefined);
const slackSubmitting = ref(false);

const addSlackBinding = () => {
    const channel = props.slackAvailableChannels.find(
        (c) => c.id === selectedSlackChannelId.value,
    );

    if (!channel || !selectedSlackProjectId.value) {
        return;
    }

    slackSubmitting.value = true;

    router.post(
        storeSlackChannel(props.organization.id).url,
        {
            channel_id: channel.id,
            channel_name: channel.name,
            project_id: selectedSlackProjectId.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                slackSubmitting.value = false;
                selectedSlackChannelId.value = undefined;
                selectedSlackProjectId.value = undefined;
            },
        },
    );
};

const removeSlackBinding = (binding: SlackBinding) => {
    router.delete(
        destroySlackChannel([props.organization.id, binding.id]).url,
        {
            preserveScroll: true,
        },
    );
};

// Dropbox
const selectedDropboxFolderId = ref<string | undefined>(undefined);
const selectedDropboxProjectId = ref<string | undefined>(undefined);
const dropboxSubmitting = ref(false);

const addDropboxBinding = () => {
    const folder = props.dropboxAvailableFolders.find(
        (f) => f.id === selectedDropboxFolderId.value,
    );

    if (!folder || !selectedDropboxProjectId.value) {
        return;
    }

    dropboxSubmitting.value = true;

    router.post(
        storeDropboxFolder(props.organization.id).url,
        {
            folder_id: folder.id,
            folder_path: folder.path,
            project_id: selectedDropboxProjectId.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                dropboxSubmitting.value = false;
                selectedDropboxFolderId.value = undefined;
                selectedDropboxProjectId.value = undefined;
            },
        },
    );
};

const removeDropboxBinding = (binding: DropboxBinding) => {
    router.delete(
        destroyDropboxFolder([props.organization.id, binding.id]).url,
        {
            preserveScroll: true,
        },
    );
};

// PDF Branding
const brandingLabel = computed(() => {
    const header = !!props.organization.pdf_header_url;
    const footer = !!props.organization.pdf_footer_url;
    if (!header && !footer) return 'Default layout';
    if (header && footer) return 'Header & footer set';
    return header ? 'Header set' : 'Footer set';
});

// "Needs Setup" badges — only for a non-default option that's been picked but not yet
// backed by saved credentials. System Default / None / not-connected are left alone.
const llmNeedsSetup = computed(
    () =>
        !!props.organization.llm_driver &&
        !props.organization.llm_config_form?.model,
);

const vectorNeedsSetup = computed(
    () =>
        !!props.organization.vector_driver &&
        props.organization.vector_driver !== 'same' &&
        !props.organization.vector_config_form?.model,
);

const meetingNeedsSetup = computed(() => {
    const provider = props.organization.meeting_provider;
    if (!provider) return false;
    const config = props.organization.meeting_config_form;
    switch (provider) {
        case 'zoom':
            return (
                !config?.account_id ||
                !config?.client_id ||
                !config?.has_client_secret
            );
        case 'teams':
            return (
                !config?.tenant_id ||
                !config?.client_id ||
                !config?.has_client_secret
            );
        case 'google_meet':
            return (
                !config?.service_account_email ||
                !config?.has_private_key ||
                !config?.impersonate_email
            );
        case 'slack':
            return !config?.has_bot_token;
        default:
            return false;
    }
});
</script>

<template>
    <div class="divide-y divide-gray-100 dark:divide-gray-800">
        <!-- Due Dates -->
        <div class="py-4">
            <div class="flex items-center gap-3">
                <input
                    id="uses_external_due_dates"
                    type="checkbox"
                    v-model="dueDatesForm.uses_external_due_dates"
                    :disabled="dueDatesForm.processing"
                    class="h-4 w-4 cursor-pointer rounded border-gray-300 text-projector-primary-600 focus:ring-projector-primary-500 dark:border-gray-700 dark:bg-gray-900"
                    @change="saveDueDates"
                />
                <Label
                    for="uses_external_due_dates"
                    class="cursor-pointer text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    Track separate internal and external due dates on tasks
                </Label>
            </div>
        </div>

        <!-- PDF Branding -->
        <div class="py-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="toggleSection('branding')"
                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        <ChevronDown
                            class="h-4 w-4 transition-transform"
                            :class="{
                                '-rotate-90': openSection !== 'branding',
                            }"
                        />
                    </button>
                    <span
                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                        >PDF Branding</span
                    >
                </div>
                <span
                    class="text-sm font-medium text-gray-900 dark:text-gray-100"
                    >{{ brandingLabel }}</span
                >
            </div>

            <div
                v-if="openSection === 'branding'"
                class="mt-4 space-y-6 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/40"
            >
                <LogoUpload
                    :current-logo-url="organization.pdf_header_url ?? null"
                    :upload-url="
                        organizationPdfBrandingRoutes.store.url({
                            organization: organization.id,
                            type: 'header',
                        })
                    "
                    :delete-url="
                        organizationPdfBrandingRoutes.destroy.url({
                            organization: organization.id,
                            type: 'header',
                        })
                    "
                    label="PDF Header Image"
                />
                <LogoUpload
                    :current-logo-url="organization.pdf_footer_url ?? null"
                    :upload-url="
                        organizationPdfBrandingRoutes.store.url({
                            organization: organization.id,
                            type: 'footer',
                        })
                    "
                    :delete-url="
                        organizationPdfBrandingRoutes.destroy.url({
                            organization: organization.id,
                            type: 'footer',
                        })
                    "
                    label="PDF Footer Image"
                />
            </div>
        </div>

        <!-- Meeting Provider -->
        <div class="py-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button
                        v-if="meetingProviderForm.meeting_provider"
                        type="button"
                        @click="toggleSection('meeting')"
                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        <ChevronDown
                            class="h-4 w-4 transition-transform"
                            :class="{
                                '-rotate-90': openSection !== 'meeting',
                            }"
                        />
                    </button>
                    <span v-else class="h-6 w-6 shrink-0"></span>
                    <span
                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                        >Meeting Provider</span
                    >
                    <span
                        v-if="meetingNeedsSetup"
                        class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-black tracking-wide text-amber-700 uppercase dark:bg-amber-900/30 dark:text-amber-300"
                        >Needs Setup</span
                    >
                </div>
                <span
                    v-if="meetingProviderForm.meeting_provider"
                    class="text-sm font-medium text-gray-900 dark:text-gray-100"
                    >{{
                        meetingProviderLabel(
                            meetingProviderForm.meeting_provider,
                        )
                    }}</span
                >
                <Select
                    v-else
                    :model-value="
                        toSelectValue(meetingProviderForm.meeting_provider)
                    "
                    :disabled="meetingProviderForm.processing"
                    @update:model-value="
                        (v) => {
                            meetingProviderForm.meeting_provider =
                                fromSelectValue(v as string);
                            onMeetingProviderChange();
                        }
                    "
                >
                    <SelectTrigger size="sm" class="w-[190px] text-sm">
                        <SelectValue>{{
                            meetingProviderLabel(
                                meetingProviderForm.meeting_provider,
                            )
                        }}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="p in MEETING_PROVIDERS"
                            :key="p.value"
                            :value="toSelectValue(p.value)"
                        >
                            {{ p.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div
                v-if="openSection === 'meeting'"
                class="mt-4 space-y-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/40"
            >
                <div class="grid gap-2">
                    <Label
                        for="meeting_provider_select"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >Meeting Provider</Label
                    >
                    <Select
                        :model-value="
                            toSelectValue(meetingProviderForm.meeting_provider)
                        "
                        :disabled="meetingProviderForm.processing"
                        @update:model-value="
                            (v) => {
                                meetingProviderForm.meeting_provider =
                                    fromSelectValue(v as string);
                                onMeetingProviderChange();
                            }
                        "
                    >
                        <SelectTrigger
                            id="meeting_provider_select"
                            size="sm"
                            class="w-[190px] text-sm"
                        >
                            <SelectValue>{{
                                meetingProviderLabel(
                                    meetingProviderForm.meeting_provider,
                                )
                            }}</SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="p in MEETING_PROVIDERS"
                                :key="p.value"
                                :value="toSelectValue(p.value)"
                            >
                                {{ p.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <button
                    type="button"
                    @click="isMeetingSetupGuideOpen = true"
                    class="flex items-center gap-1 text-[10px] font-black tracking-widest text-projector-primary-500 uppercase transition-colors hover:text-projector-primary-700 dark:hover:text-projector-primary-300"
                >
                    <HelpCircle class="h-3.5 w-3.5" />
                    Setup Guide
                </button>

                <div v-if="isZoom" class="grid gap-2">
                    <Label
                        for="meeting_account_id"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >Account ID</Label
                    >
                    <Input
                        id="meeting_account_id"
                        v-model="meetingProviderForm.meeting_config.account_id"
                        placeholder="Your Zoom Account ID"
                        class="h-10 font-mono text-sm"
                    />
                </div>

                <div v-if="isTeams" class="grid gap-2">
                    <Label
                        for="meeting_tenant_id"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >Tenant ID</Label
                    >
                    <Input
                        id="meeting_tenant_id"
                        v-model="meetingProviderForm.meeting_config.tenant_id"
                        placeholder="Your Azure Tenant ID"
                        class="h-10 font-mono text-sm"
                    />
                </div>

                <template v-if="!isGoogleMeet && !isSlackMeeting">
                    <div class="grid gap-2">
                        <Label
                            for="meeting_client_id"
                            class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                            >Client ID</Label
                        >
                        <Input
                            id="meeting_client_id"
                            v-model="
                                meetingProviderForm.meeting_config.client_id
                            "
                            placeholder="OAuth Client ID"
                            class="h-10 font-mono text-sm"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label
                            for="meeting_client_secret"
                            class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                            >Client Secret</Label
                        >
                        <Input
                            id="meeting_client_secret"
                            v-model="
                                meetingProviderForm.meeting_config.client_secret
                            "
                            type="password"
                            autocomplete="off"
                            :placeholder="meetingClientSecretPlaceholder"
                            class="h-10 font-mono text-sm"
                        />
                    </div>
                </template>

                <template v-if="isGoogleMeet">
                    <div class="grid gap-2">
                        <Label
                            for="meeting_service_account_email"
                            class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                            >Service Account Email</Label
                        >
                        <Input
                            id="meeting_service_account_email"
                            v-model="
                                meetingProviderForm.meeting_config
                                    .service_account_email
                            "
                            type="email"
                            placeholder="name@project.iam.gserviceaccount.com"
                            class="h-10 font-mono text-sm"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label
                            for="meeting_private_key"
                            class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                            >Private Key (PEM)</Label
                        >
                        <textarea
                            id="meeting_private_key"
                            v-model="
                                meetingProviderForm.meeting_config.private_key
                            "
                            rows="6"
                            :placeholder="meetingPrivateKeyPlaceholder"
                            class="w-full resize-y rounded-xl border border-gray-200 bg-white px-4 py-3 font-mono text-xs text-gray-700 outline-none focus:border-projector-primary-500 focus:ring-2 focus:ring-projector-primary-500/30 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-200"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label
                            for="meeting_impersonate_email"
                            class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                            >Impersonate Email</Label
                        >
                        <Input
                            id="meeting_impersonate_email"
                            v-model="
                                meetingProviderForm.meeting_config
                                    .impersonate_email
                            "
                            type="email"
                            placeholder="user@yourworkspace.com"
                            class="h-10 font-mono text-sm"
                        />
                    </div>
                </template>

                <template v-if="isSlackMeeting">
                    <div class="grid gap-2">
                        <Label
                            for="meeting_bot_token"
                            class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                            >Bot User OAuth Token</Label
                        >
                        <Input
                            id="meeting_bot_token"
                            v-model="
                                meetingProviderForm.meeting_config.bot_token
                            "
                            type="password"
                            autocomplete="off"
                            :placeholder="meetingBotTokenPlaceholder"
                            class="h-10 font-mono text-sm"
                        />
                    </div>
                </template>

                <div class="flex justify-end">
                    <Button
                        type="button"
                        size="sm"
                        :disabled="meetingProviderForm.processing"
                        @click="saveMeetingProvider"
                        >Save</Button
                    >
                </div>
            </div>
        </div>

        <!-- Slack -->
        <div class="py-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button
                        v-if="slackConnected"
                        type="button"
                        @click="toggleSection('slack')"
                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        <ChevronDown
                            class="h-4 w-4 transition-transform"
                            :class="{
                                '-rotate-90': openSection !== 'slack',
                            }"
                        />
                    </button>
                    <span v-else class="h-6 w-6 shrink-0"></span>
                    <span
                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                        >Slack</span
                    >
                </div>
                <span
                    v-if="slackConnected"
                    class="text-sm font-medium text-gray-900 dark:text-gray-100"
                    >Connected</span
                >
                <a
                    v-else-if="slackConfigured"
                    :href="connectSlack(organization.id).url"
                >
                    <Button type="button" variant="outline" size="sm"
                        >Connect</Button
                    >
                </a>
                <span
                    v-else
                    class="text-sm font-medium text-gray-900 dark:text-gray-100"
                    >Not configured</span
                >
            </div>

            <div
                v-if="openSection === 'slack'"
                class="mt-4 space-y-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/40"
            >
                <div
                    class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-gray-950"
                >
                    <div class="space-y-0.5">
                        <p
                            class="text-sm font-medium text-slate-900 dark:text-slate-100"
                        >
                            Connected
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ slackTeamName }}
                        </p>
                    </div>
                    <Form
                        v-bind="disconnectSlack.form(organization.id)"
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
                        v-if="slackBindings.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No channels are bound yet.
                    </p>
                    <div
                        v-for="binding in slackBindings"
                        :key="binding.id"
                        class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-gray-950"
                    >
                        <div class="space-y-0.5">
                            <p
                                class="text-sm font-medium text-slate-900 dark:text-slate-100"
                            >
                                #{{ binding.channel_name }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ binding.project.name }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="secondary"
                            @click="removeSlackBinding(binding)"
                            >Remove</Button
                        >
                    </div>
                </div>

                <div
                    class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-gray-950"
                >
                    <p
                        class="text-sm font-medium text-slate-900 dark:text-slate-100"
                    >
                        Add A Channel
                    </p>
                    <p
                        v-if="slackAvailableChannels.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No unbound channels found — the bot may need to be
                        invited to more channels, or every visible channel is
                        already bound.
                    </p>
                    <div v-else class="flex flex-wrap items-center gap-3">
                        <Select
                            :model-value="selectedSlackChannelId"
                            @update:model-value="
                                (v) => (selectedSlackChannelId = v as string)
                            "
                        >
                            <SelectTrigger class="h-9 w-[220px] text-[13px]">
                                <SelectValue placeholder="Select a channel…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="channel in slackAvailableChannels"
                                    :key="channel.id"
                                    :value="channel.id"
                                    ># {{ channel.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Select
                            :model-value="selectedSlackProjectId"
                            @update:model-value="
                                (v) => (selectedSlackProjectId = v as string)
                            "
                        >
                            <SelectTrigger class="h-9 w-[220px] text-[13px]">
                                <SelectValue placeholder="Select a project…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="project in slackProjects"
                                    :key="project.id"
                                    :value="project.id"
                                    >{{ project.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Button
                            type="button"
                            :disabled="
                                !selectedSlackChannelId ||
                                !selectedSlackProjectId ||
                                slackSubmitting
                            "
                            @click="addSlackBinding"
                            >Add</Button
                        >
                    </div>
                </div>
            </div>
        </div>

        <!-- Dropbox -->
        <div class="py-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button
                        v-if="dropboxConnected"
                        type="button"
                        @click="toggleSection('dropbox')"
                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        <ChevronDown
                            class="h-4 w-4 transition-transform"
                            :class="{
                                '-rotate-90': openSection !== 'dropbox',
                            }"
                        />
                    </button>
                    <span v-else class="h-6 w-6 shrink-0"></span>
                    <span
                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                        >Dropbox</span
                    >
                </div>
                <span
                    v-if="dropboxConnected"
                    class="text-sm font-medium text-gray-900 dark:text-gray-100"
                    >Connected</span
                >
                <a
                    v-else-if="dropboxConfigured"
                    :href="connectDropbox(organization.id).url"
                >
                    <Button type="button" variant="outline" size="sm"
                        >Connect</Button
                    >
                </a>
                <span
                    v-else
                    class="text-sm font-medium text-gray-900 dark:text-gray-100"
                    >Not configured</span
                >
            </div>

            <div
                v-if="openSection === 'dropbox'"
                class="mt-4 space-y-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/40"
            >
                <div
                    class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-gray-950"
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
                        v-bind="disconnectDropbox.form(organization.id)"
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
                        class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-gray-950"
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
                            @click="removeDropboxBinding(binding)"
                            >Remove</Button
                        >
                    </div>
                </div>

                <div
                    class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-gray-950"
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
                        No unbound top-level folders found in the connected
                        account.
                    </p>
                    <div v-else class="flex flex-wrap items-center gap-3">
                        <Select
                            :model-value="selectedDropboxFolderId"
                            @update:model-value="
                                (v) => (selectedDropboxFolderId = v as string)
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
                            :model-value="selectedDropboxProjectId"
                            @update:model-value="
                                (v) => (selectedDropboxProjectId = v as string)
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
                                !selectedDropboxFolderId ||
                                !selectedDropboxProjectId ||
                                dropboxSubmitting
                            "
                            @click="addDropboxBinding"
                            >Add</Button
                        >
                    </div>
                </div>
            </div>
        </div>

        <!-- LLM Driver -->
        <div class="py-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button
                        v-if="llmDriverForm.llm_driver"
                        type="button"
                        @click="toggleSection('llm')"
                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        <ChevronDown
                            class="h-4 w-4 transition-transform"
                            :class="{ '-rotate-90': openSection !== 'llm' }"
                        />
                    </button>
                    <span v-else class="h-6 w-6 shrink-0"></span>
                    <span
                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                        >LLM Driver</span
                    >
                    <span
                        v-if="llmNeedsSetup"
                        class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-black tracking-wide text-amber-700 uppercase dark:bg-amber-900/30 dark:text-amber-300"
                        >Needs Setup</span
                    >
                </div>
                <span
                    v-if="llmDriverForm.llm_driver"
                    class="text-sm font-medium text-gray-900 dark:text-gray-100"
                    >{{ llmDriverLabel(llmDriverForm.llm_driver) }}</span
                >
                <Select
                    v-else
                    :model-value="toSelectValue(llmDriverForm.llm_driver)"
                    :disabled="llmDriverForm.processing"
                    @update:model-value="
                        (v) => {
                            llmDriverForm.llm_driver = fromSelectValue(
                                v as string,
                            );
                            onLlmDriverChange();
                        }
                    "
                >
                    <SelectTrigger size="sm" class="w-[190px] text-sm">
                        <SelectValue>{{
                            llmDriverLabel(llmDriverForm.llm_driver)
                        }}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="d in LLM_DRIVERS"
                            :key="d.value"
                            :value="toSelectValue(d.value)"
                        >
                            {{ d.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div
                v-if="openSection === 'llm'"
                class="mt-4 space-y-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/40"
            >
                <div class="grid gap-2">
                    <Label
                        for="llm_driver_select"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >LLM Driver</Label
                    >
                    <Select
                        :model-value="toSelectValue(llmDriverForm.llm_driver)"
                        :disabled="llmDriverForm.processing"
                        @update:model-value="
                            (v) => {
                                llmDriverForm.llm_driver = fromSelectValue(
                                    v as string,
                                );
                                onLlmDriverChange();
                            }
                        "
                    >
                        <SelectTrigger
                            id="llm_driver_select"
                            size="sm"
                            class="w-[190px] text-sm"
                        >
                            <SelectValue>{{
                                llmDriverLabel(llmDriverForm.llm_driver)
                            }}</SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="d in LLM_DRIVERS"
                                :key="d.value"
                                :value="toSelectValue(d.value)"
                            >
                                {{ d.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="llmUsesApiKey" class="grid gap-2">
                    <Label
                        for="llm_key"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >API Key</Label
                    >
                    <Input
                        id="llm_key"
                        v-model="llmDriverForm.llm_config.key"
                        type="password"
                        autocomplete="off"
                        :placeholder="llmKeyPlaceholder"
                        class="h-10 font-mono text-sm"
                    />
                </div>
                <div v-if="llmUsesHost" class="grid gap-2">
                    <Label
                        for="llm_host"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >Ollama Host URL</Label
                    >
                    <Input
                        id="llm_host"
                        v-model="llmDriverForm.llm_config.host"
                        type="url"
                        placeholder="http://localhost:11434"
                        class="h-10 font-mono text-sm"
                    />
                </div>
                <div class="grid gap-2">
                    <Label
                        for="llm_model"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >Model</Label
                    >
                    <Input
                        id="llm_model"
                        v-model="llmDriverForm.llm_config.model"
                        :placeholder="llmDefaultModelPlaceholder"
                        class="h-10 font-mono text-sm"
                    />
                </div>
                <div class="flex justify-end">
                    <Button
                        type="button"
                        size="sm"
                        :disabled="llmDriverForm.processing"
                        @click="saveLlmDriver"
                        >Save</Button
                    >
                </div>
            </div>
        </div>

        <!-- Embeddings Driver -->
        <div class="py-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button
                        v-if="vectorDriverForm.vector_driver"
                        type="button"
                        @click="toggleSection('vector')"
                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        <ChevronDown
                            class="h-4 w-4 transition-transform"
                            :class="{
                                '-rotate-90': openSection !== 'vector',
                            }"
                        />
                    </button>
                    <span v-else class="h-6 w-6 shrink-0"></span>
                    <span
                        class="text-sm font-medium text-gray-700 dark:text-gray-300"
                        >Embeddings Driver</span
                    >
                    <span
                        v-if="vectorNeedsSetup"
                        class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-black tracking-wide text-amber-700 uppercase dark:bg-amber-900/30 dark:text-amber-300"
                        >Needs Setup</span
                    >
                </div>
                <span
                    v-if="vectorDriverForm.vector_driver"
                    class="text-sm font-medium text-gray-900 dark:text-gray-100"
                    >{{
                        vectorDriverLabel(vectorDriverForm.vector_driver)
                    }}</span
                >
                <Select
                    v-else
                    :model-value="toSelectValue(vectorDriverForm.vector_driver)"
                    :disabled="vectorDriverForm.processing"
                    @update:model-value="
                        (v) => {
                            vectorDriverForm.vector_driver = fromSelectValue(
                                v as string,
                            );
                            onVectorDriverChange();
                        }
                    "
                >
                    <SelectTrigger size="sm" class="w-[190px] text-sm">
                        <SelectValue>{{
                            vectorDriverLabel(vectorDriverForm.vector_driver)
                        }}</SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="d in VECTOR_DRIVERS"
                            :key="d.value"
                            :value="toSelectValue(d.value)"
                            :disabled="
                                d.value === 'same' && vectorSameAsLlmDisabled
                            "
                        >
                            {{ d.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div
                v-if="openSection === 'vector'"
                class="mt-4 space-y-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/40"
            >
                <div class="grid gap-2">
                    <Label
                        for="vector_driver_select"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >Embeddings Driver</Label
                    >
                    <Select
                        :model-value="
                            toSelectValue(vectorDriverForm.vector_driver)
                        "
                        :disabled="vectorDriverForm.processing"
                        @update:model-value="
                            (v) => {
                                vectorDriverForm.vector_driver =
                                    fromSelectValue(v as string);
                                onVectorDriverChange();
                            }
                        "
                    >
                        <SelectTrigger
                            id="vector_driver_select"
                            size="sm"
                            class="w-[190px] text-sm"
                        >
                            <SelectValue>{{
                                vectorDriverLabel(
                                    vectorDriverForm.vector_driver,
                                )
                            }}</SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="d in VECTOR_DRIVERS"
                                :key="d.value"
                                :value="toSelectValue(d.value)"
                                :disabled="
                                    d.value === 'same' &&
                                    vectorSameAsLlmDisabled
                                "
                            >
                                {{ d.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="vectorUsesApiKey" class="grid gap-2">
                    <Label
                        for="vector_key"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >API Key</Label
                    >
                    <Input
                        id="vector_key"
                        v-model="vectorDriverForm.vector_config.key"
                        type="password"
                        autocomplete="off"
                        :placeholder="vectorKeyPlaceholder"
                        class="h-10 font-mono text-sm"
                    />
                </div>
                <div v-if="vectorUsesHost" class="grid gap-2">
                    <Label
                        for="vector_host"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >Ollama Host URL</Label
                    >
                    <Input
                        id="vector_host"
                        v-model="vectorDriverForm.vector_config.host"
                        type="url"
                        placeholder="http://localhost:11434"
                        class="h-10 font-mono text-sm"
                    />
                </div>
                <div v-if="vectorShowsConfig" class="grid gap-2">
                    <Label
                        for="vector_model"
                        class="px-1 text-[10px] font-black tracking-widest text-gray-400 uppercase"
                        >Embedding Model</Label
                    >
                    <Input
                        id="vector_model"
                        v-model="vectorDriverForm.vector_config.model"
                        :placeholder="vectorDefaultModelPlaceholder"
                        class="h-10 font-mono text-sm"
                    />
                </div>
                <div class="flex justify-end">
                    <Button
                        type="button"
                        size="sm"
                        :disabled="vectorDriverForm.processing"
                        @click="saveVectorDriver"
                        >Save</Button
                    >
                </div>
            </div>
        </div>
    </div>

    <MeetingProviderSetupModal
        :open="isMeetingSetupGuideOpen"
        :provider="meetingProviderForm.meeting_provider"
        @close="isMeetingSetupGuideOpen = false"
    />
</template>
