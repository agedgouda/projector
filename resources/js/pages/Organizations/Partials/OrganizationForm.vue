<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { toast } from "vue-sonner";
import { HelpCircle } from 'lucide-vue-next';
import organizationRoutes from '@/routes/organizations/index';
import organizationLogoRoutes from '@/routes/organizations/logo/index';
import { LLM_DRIVERS, VECTOR_DRIVERS, MEETING_PROVIDERS } from '@/lib/constants';
import MeetingProviderSetupModal from '@/components/MeetingProviderSetupModal.vue';
import LogoUpload from '@/components/LogoUpload.vue';
import LogoFileInput from '@/components/LogoFileInput.vue';

interface AiConfigForm {
    model: string;
    host: string;
    has_key: boolean;
}

interface MeetingConfigForm {
    account_id: string;
    tenant_id: string;
    client_id: string;
    client_secret: string;
    service_account_email: string;
    impersonate_email: string;
    private_key: string;
    bot_token: string;
    has_client_secret?: boolean;
    has_private_key?: boolean;
    has_bot_token?: boolean;
}

interface Props {
    organization?: Organization & {
        logo_url?: string | null;
        llm_config_form?: AiConfigForm;
        vector_config_form?: AiConfigForm;
        meeting_config_form?: MeetingConfigForm;
    };
}

const props = defineProps<Props>();
const emit = defineEmits(['success', 'cancel']);

const isEditing = !!props.organization;

const form = useForm({
    name: props.organization?.name || '',
    logo: null as File | null,
    llm_driver: props.organization?.llm_driver || '',
    llm_config: {
        key:   '',
        model: props.organization?.llm_config_form?.model || '',
        host:  props.organization?.llm_config_form?.host  || '',
    },
    vector_driver: props.organization?.vector_driver || '',
    vector_config: {
        key:   '',
        model: props.organization?.vector_config_form?.model || '',
        host:  props.organization?.vector_config_form?.host  || '',
    },
    meeting_provider: props.organization?.meeting_provider || '',
    meeting_config: {
        account_id:            props.organization?.meeting_config_form?.account_id            || '',
        tenant_id:             props.organization?.meeting_config_form?.tenant_id             || '',
        client_id:             props.organization?.meeting_config_form?.client_id             || '',
        client_secret:         '',
        service_account_email: props.organization?.meeting_config_form?.service_account_email || '',
        impersonate_email:     props.organization?.meeting_config_form?.impersonate_email     || '',
        private_key:           '',
        bot_token:             '',
    } as MeetingConfigForm,
});

const usesApiKey = computed(() =>
    ['openai', 'gemini', 'claude'].includes(form.llm_driver)
);

const usesHost = computed(() => form.llm_driver === 'ollama');

const keyPlaceholder = computed(() => {
    if (!form.llm_driver) return '';
    return props.organization?.llm_config_form?.has_key
        ? 'Leave blank to keep existing key'
        : 'Enter API key';
});

const defaultModelPlaceholder = computed((): string => {
    const defaults: Record<string, string> = {
        openai: 'gpt-4o-mini',
        gemini: 'gemini-2.0-flash',
        claude: 'claude-sonnet-4-6',
        ollama: 'deepseek-r1:8b',
    };
    return defaults[form.llm_driver] ?? '';
});

// Vector driver — "same" is only valid when the LLM driver is an embedding-capable provider
const sameAsLlmDisabled = computed(() =>
    !form.llm_driver || form.llm_driver === 'claude'
);

const vectorUsesApiKey = computed(() =>
    ['openai', 'gemini'].includes(form.vector_driver)
);

const vectorUsesHost = computed(() => form.vector_driver === 'ollama');

const vectorShowsConfig = computed(() =>
    !!form.vector_driver && form.vector_driver !== 'same'
);

const vectorKeyPlaceholder = computed(() => {
    if (!form.vector_driver) return '';
    return props.organization?.vector_config_form?.has_key
        ? 'Leave blank to keep existing key'
        : 'Enter API key';
});

const vectorDefaultModelPlaceholder = computed((): string => {
    const defaults: Record<string, string> = {
        openai: 'text-embedding-3-small',
        gemini: 'text-embedding-004',
        ollama: 'nomic-embed-text',
    };
    return defaults[form.vector_driver] ?? '';
});

const meetingShowsConfig = computed(() => !!form.meeting_provider);
const isSetupGuideOpen = ref(false);
const isZoom = computed(() => form.meeting_provider === 'zoom');
const isTeams = computed(() => form.meeting_provider === 'teams');
const isGoogleMeet = computed(() => form.meeting_provider === 'google_meet');
const isSlack = computed(() => form.meeting_provider === 'slack');

const clientSecretPlaceholder = computed(() =>
    props.organization?.meeting_config_form?.has_client_secret
        ? 'Leave blank to keep existing secret'
        : 'Enter client secret'
);

const privateKeyPlaceholder = computed(() =>
    props.organization?.meeting_config_form?.has_private_key
        ? 'Leave blank to keep existing private key'
        : 'Paste PEM private key here'
);

const botTokenPlaceholder = computed(() =>
    props.organization?.meeting_config_form?.has_bot_token
        ? 'Leave blank to keep existing bot token'
        : 'xoxb-...'
);

const submit = () => {
    const url = isEditing
        ? organizationRoutes.update.url(props.organization!.id)
        : organizationRoutes.store.url();

    const method = isEditing ? 'patch' : 'post';

    form[method](url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            toast.success(isEditing ? 'Organization updated' : 'Organization created');
            emit('success');
        },
        onError: () => {
            toast.error('Submission failed', {
                description: form.errors.name || 'Check required fields.'
            });
        }
    });
};
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <LogoUpload
            v-if="isEditing && organization"
            :current-logo-url="organization.logo_url ?? null"
            :upload-url="organizationLogoRoutes.store.url(organization.id)"
            :delete-url="organizationLogoRoutes.destroy.url(organization.id)"
            label="Organization Logo"
        />
        <LogoFileInput
            v-else
            v-model="form.logo"
            label="Organization Logo"
            :error="form.errors.logo"
        />

        <div class="grid gap-2">
            <Label for="name" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                Organization Name
            </Label>
            <Input
                id="name"
                v-model="form.name"
                class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-bold"
                :class="{ 'border-red-500': form.errors.name }"
            />
            <div v-if="form.errors.name" class="text-[10px] text-red-500 font-bold px-1 uppercase tracking-tight">
                {{ form.errors.name }}
            </div>
        </div>

        <!-- AI Driver Overrides -->
        <div class="pt-4 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">
                    AI Driver
                </p>

                <div class="grid gap-2">
                    <Label for="llm_driver" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                        LLM Driver
                    </Label>
                    <select
                        id="llm_driver"
                        v-model="form.llm_driver"
                        class="h-12 w-full rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-950 px-4 text-sm font-medium text-gray-700 dark:text-gray-200 outline-none focus:ring-2 focus:ring-projector-primary-500/30 focus:border-projector-primary-500"
                    >
                        <option v-for="d in LLM_DRIVERS" :key="d.value" :value="d.value">
                            {{ d.label }}
                        </option>
                    </select>
                    <p class="text-[10px] text-gray-400 px-1">
                        Override the system-wide AI driver for this organization. Leave as "System Default" to use the platform setting.
                    </p>
                </div>

                <template v-if="form.llm_driver">
                    <!-- API key field (OpenAI / Gemini / Claude) -->
                    <div v-if="usesApiKey" class="grid gap-2">
                        <Label for="llm_key" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            API Key
                        </Label>
                        <Input
                            id="llm_key"
                            v-model="form.llm_config.key"
                            type="password"
                            autocomplete="off"
                            :placeholder="keyPlaceholder"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                    </div>

                    <!-- Host field (Ollama) -->
                    <div v-if="usesHost" class="grid gap-2">
                        <Label for="llm_host" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Ollama Host URL
                        </Label>
                        <Input
                            id="llm_host"
                            v-model="form.llm_config.host"
                            type="url"
                            :placeholder="'http://localhost:11434'"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                    </div>

                    <!-- Model -->
                    <div class="grid gap-2">
                        <Label for="llm_model" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Model
                        </Label>
                        <Input
                            id="llm_model"
                            v-model="form.llm_config.model"
                            :placeholder="defaultModelPlaceholder"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                        <p class="text-[10px] text-gray-400 px-1">
                            Leave blank to use the default model for this driver.
                        </p>
                    </div>
                </template>
            </div>

            <!-- Vector / Embeddings Driver -->
            <div class="pt-4 border-t border-gray-100 dark:border-gray-800 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">
                    Embeddings Driver Override
                </p>

                <div class="grid gap-2">
                    <Label for="vector_driver" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                        Vector Driver
                    </Label>
                    <select
                        id="vector_driver"
                        v-model="form.vector_driver"
                        class="h-12 w-full rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-950 px-4 text-sm font-medium text-gray-700 dark:text-gray-200 outline-none focus:ring-2 focus:ring-projector-primary-500/30 focus:border-projector-primary-500"
                    >
                        <option
                            v-for="d in VECTOR_DRIVERS"
                            :key="d.value"
                            :value="d.value"
                            :disabled="d.value === 'same' && sameAsLlmDisabled"
                        >
                            {{ d.label }}{{ d.value === 'same' && sameAsLlmDisabled ? ' (requires embedding-capable LLM)' : '' }}
                        </option>
                    </select>
                    <p class="text-[10px] text-gray-400 px-1">
                        Override the embeddings provider. "Same as LLM Driver" reuses your LLM credentials — unavailable when LLM is Claude (no embeddings API) or System Default.
                    </p>
                </div>

                <template v-if="vectorShowsConfig">
                    <!-- API key field (OpenAI / Gemini) -->
                    <div v-if="vectorUsesApiKey" class="grid gap-2">
                        <Label for="vector_key" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            API Key
                        </Label>
                        <Input
                            id="vector_key"
                            v-model="form.vector_config.key"
                            type="password"
                            autocomplete="off"
                            :placeholder="vectorKeyPlaceholder"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                    </div>

                    <!-- Host field (Ollama) -->
                    <div v-if="vectorUsesHost" class="grid gap-2">
                        <Label for="vector_host" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Ollama Host URL
                        </Label>
                        <Input
                            id="vector_host"
                            v-model="form.vector_config.host"
                            type="url"
                            placeholder="http://localhost:11434"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                    </div>

                    <!-- Model -->
                    <div class="grid gap-2">
                        <Label for="vector_model" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Embedding Model
                        </Label>
                        <Input
                            id="vector_model"
                            v-model="form.vector_config.model"
                            :placeholder="vectorDefaultModelPlaceholder"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                        <p class="text-[10px] text-gray-400 px-1">
                            Leave blank to use the default embedding model for this driver.
                        </p>
                    </div>
                </template>
        </div>

        <!-- Meeting Provider -->
        <div class="pt-4 border-t border-gray-100 dark:border-gray-800 space-y-4">
            <div class="flex items-center justify-between">
                <p class="text-[10px] font-black uppercase tracking-widest text-gray-400">
                    Meeting Transcripts
                </p>
                <button
                    v-if="form.meeting_provider"
                    type="button"
                    @click="isSetupGuideOpen = true"
                    class="flex items-center gap-1 text-[10px] font-black uppercase tracking-widest text-projector-primary-500 hover:text-projector-primary-700 dark:hover:text-projector-primary-300 transition-colors"
                >
                    <HelpCircle class="w-3.5 h-3.5" />
                    Setup Guide
                </button>
            </div>

            <div class="grid gap-2">
                <Label for="meeting_provider" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                    Meeting Provider
                </Label>
                <select
                    id="meeting_provider"
                    v-model="form.meeting_provider"
                    class="h-12 w-full rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-950 px-4 text-sm font-medium text-gray-700 dark:text-gray-200 outline-none focus:ring-2 focus:ring-projector-primary-500/30 focus:border-projector-primary-500"
                >
                    <option v-for="p in MEETING_PROVIDERS" :key="p.value" :value="p.value">
                        {{ p.label }}
                    </option>
                </select>
                <p class="text-[10px] text-gray-400 px-1">
                    Select a video conferencing provider to enable meeting transcript capture.
                </p>
            </div>

            <template v-if="meetingShowsConfig">
                <!-- Zoom: Account ID -->
                <div v-if="isZoom" class="grid gap-2">
                    <Label for="meeting_account_id" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                        Account ID
                    </Label>
                    <Input
                        id="meeting_account_id"
                        v-model="form.meeting_config.account_id"
                        placeholder="Your Zoom Account ID"
                        class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                    />
                </div>

                <!-- Teams: Tenant ID -->
                <div v-if="isTeams" class="grid gap-2">
                    <Label for="meeting_tenant_id" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                        Tenant ID
                    </Label>
                    <Input
                        id="meeting_tenant_id"
                        v-model="form.meeting_config.tenant_id"
                        placeholder="Your Azure Tenant ID"
                        class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                    />
                </div>

                <!-- Zoom / Teams: Client ID + Secret -->
                <template v-if="!isGoogleMeet && !isSlack">
                    <div class="grid gap-2">
                        <Label for="meeting_client_id" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Client ID
                        </Label>
                        <Input
                            id="meeting_client_id"
                            v-model="form.meeting_config.client_id"
                            placeholder="OAuth Client ID"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="meeting_client_secret" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Client Secret
                        </Label>
                        <Input
                            id="meeting_client_secret"
                            v-model="form.meeting_config.client_secret"
                            type="password"
                            autocomplete="off"
                            :placeholder="clientSecretPlaceholder"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                    </div>
                </template>

                <!-- Google Meet: Service Account fields -->
                <template v-if="isGoogleMeet">
                    <div class="grid gap-2">
                        <Label for="meeting_service_account_email" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Service Account Email
                        </Label>
                        <Input
                            id="meeting_service_account_email"
                            v-model="form.meeting_config.service_account_email"
                            type="email"
                            placeholder="name@project.iam.gserviceaccount.com"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="meeting_private_key" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Private Key (PEM)
                        </Label>
                        <textarea
                            id="meeting_private_key"
                            v-model="form.meeting_config.private_key"
                            rows="6"
                            :placeholder="privateKeyPlaceholder"
                            class="w-full rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-950 px-4 py-3 font-mono text-xs text-gray-700 dark:text-gray-200 outline-none focus:ring-2 focus:ring-projector-primary-500/30 focus:border-projector-primary-500 resize-y"
                        />
                        <p class="text-[10px] text-gray-400 px-1">
                            Paste the full PEM private key from your Google service account JSON file.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="meeting_impersonate_email" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Impersonate Email
                        </Label>
                        <Input
                            id="meeting_impersonate_email"
                            v-model="form.meeting_config.impersonate_email"
                            type="email"
                            placeholder="user@yourworkspace.com"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                        <p class="text-[10px] text-gray-400 px-1">
                            A Google Workspace user the service account will impersonate (requires domain-wide delegation).
                        </p>
                    </div>
                </template>

                <!-- Slack: Bot Token -->
                <template v-if="isSlack">
                    <div class="grid gap-2">
                        <Label for="meeting_bot_token" class="text-[10px] font-black uppercase tracking-widest text-gray-400 px-1">
                            Bot User OAuth Token
                        </Label>
                        <Input
                            id="meeting_bot_token"
                            v-model="form.meeting_config.bot_token"
                            type="password"
                            autocomplete="off"
                            :placeholder="botTokenPlaceholder"
                            class="h-12 rounded-xl bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-800 font-mono text-sm"
                        />
                        <p class="text-[10px] text-gray-400 px-1">
                            The Bot User OAuth Token (starts with <span class="font-mono">xoxb-</span>) from your Slack app's OAuth &amp; Permissions page.
                        </p>
                    </div>
                </template>
            </template>
        </div>

        <MeetingProviderSetupModal
            :open="isSetupGuideOpen"
            :provider="form.meeting_provider"
            @close="isSetupGuideOpen = false"
        />

        <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-800">
            <Button type="button" @click="emit('cancel')" class="bg-white text-projector-primary-600 border border-projector-primary-600 font-black uppercase text-[10px] tracking-widest px-8 h-12 rounded-xl hover:bg-projector-primary-50 dark:bg-transparent dark:text-projector-primary-400 dark:border-projector-primary-400 dark:hover:bg-projector-primary-950/30">
                Cancel
            </Button>
            <Button
                type="submit"
                :disabled="form.processing"
                class="bg-projector-primary-600 hover:bg-projector-primary-700 text-white font-black uppercase text-[10px] tracking-widest px-8 h-12 rounded-xl shadow-lg"
            >
                {{ isEditing ? 'Save Changes' : 'Create Organization' }}
            </Button>
        </div>
    </form>
</template>
