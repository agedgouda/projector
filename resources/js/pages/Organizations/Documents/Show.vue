<script setup lang="ts">
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import AvailableOrgRecordings from '@/pages/Organizations/Partials/AvailableOrgRecordings.vue';
import orgDocumentsRoutes from '@/routes/organizations/documents/index';
import statusMeetingsRoutes from '@/routes/status-meetings/index';
import { type BreadcrumbItem } from '@/types';
import { Deferred, Head, router, useForm, usePage } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import { ArrowLeft, Edit2, Trash2, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';

interface OrgDocument {
    id: string;
    name: string;
    type: string;
    content: string;
    metadata: Record<string, any> | null;
    processed_at: string | null;
    created_at: string;
    creator?: { name: string } | null;
    editor?: { name: string } | null;
}

interface RecordingsData {
    recordings: Recording[];
    importedIds: string[];
    providerError: string | null;
}

const props = defineProps<{
    organization: { id: string; name: string };
    item: OrgDocument;
    canManage: boolean;
    meetingProvider: string | null;
    recordingsData: RecordingsData;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Status Meetings', href: statusMeetingsRoutes.index.url() },
    { title: props.item.name ?? 'Status Meeting', href: '' },
];

const activeTab = ref<'documentation' | 'recordings'>('documentation');
const isEditing = ref(false);
const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);

const form = useForm({
    name: props.item.name ?? '',
    type: props.item.type,
    content: props.item.content,
    metadata: props.item.metadata ?? {},
});

const toggleEdit = () => {
    if (isEditing.value) {
        form.reset();
    }
    isEditing.value = !isEditing.value;
};

const handleSubmit = () => {
    form.patch(
        orgDocumentsRoutes.update({
            organization: props.organization.id,
            orgDocument: props.item.id,
        }).url,
        {
            onSuccess: () => {
                toast.success('Status meeting updated.');
                isEditing.value = false;
            },
            onError: () => toast.error('Please correct the errors.'),
        },
    );
};

const handleDelete = () => {
    isDeleting.value = true;
    router.delete(
        orgDocumentsRoutes.destroy({
            organization: props.organization.id,
            orgDocument: props.item.id,
        }).url,
        {
            onSuccess: () => toast.success('Status meeting deleted.'),
            onFinish: () => {
                isDeleting.value = false;
            },
        },
    );
};

const page = usePage<{ flash?: { success?: string; error?: string } }>();
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
    },
    { deep: true, immediate: true },
);

const sanitize = (html: string) => DOMPurify.sanitize(html);
</script>

<template>
    <Head :title="item.name ?? 'Status Meeting'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-8 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <button
                    @click="router.visit(statusMeetingsRoutes.index.url())"
                    class="flex items-center gap-2 text-sm text-slate-500 transition-colors hover:text-projector-primary-600"
                >
                    <ArrowLeft class="h-3 w-3" />
                    Status Meetings
                </button>

                <div v-if="canManage" class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="toggleEdit"
                        class="relative flex h-8 w-24 items-center justify-center text-[10px] font-black tracking-widest uppercase"
                    >
                        <div
                            class="absolute left-3 flex items-center justify-center"
                        >
                            <component
                                :is="isEditing ? X : Edit2"
                                class="h-3 w-3"
                            />
                        </div>
                        <span class="ml-4">{{
                            isEditing ? 'Cancel' : 'Edit'
                        }}</span>
                    </Button>

                    <Button
                        variant="ghost"
                        size="icon"
                        @click="isDeleteModalOpen = true"
                        class="h-8 w-8 text-slate-400 hover:bg-red-50 hover:text-red-600"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div
                class="flex items-center border-b border-gray-200 dark:border-gray-700"
            >
                <button
                    v-for="tab in ['documentation', 'recordings']"
                    :key="tab"
                    @click="activeTab = tab as 'documentation' | 'recordings'"
                    :class="[
                        '-mb-[1px] border-b-2 px-8 py-4 text-[10px] font-black tracking-[0.2em] uppercase transition-all',
                        activeTab === tab
                            ? 'border-projector-primary-500 text-projector-primary-600'
                            : 'border-transparent text-gray-400 hover:text-gray-600',
                    ]"
                >
                    {{
                        tab === 'documentation' ? 'Documentation' : 'Recordings'
                    }}
                </button>
            </div>

            <!-- Documentation Tab -->
            <div v-show="activeTab === 'documentation'">
                <div
                    v-if="isEditing"
                    class="max-w-3xl space-y-5 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <form @submit.prevent="handleSubmit" class="space-y-5">
                        <div class="space-y-1.5">
                            <label
                                class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                                >Title</label
                            >
                            <input
                                v-model="form.name"
                                type="text"
                                class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-900 focus:ring-2 focus:ring-projector-primary-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                            <p
                                v-if="form.errors.name"
                                class="text-xs text-red-500"
                            >
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <label
                                class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                                >Notes / Transcript</label
                            >
                            <textarea
                                v-model="form.content"
                                rows="16"
                                class="w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-sm leading-relaxed text-slate-900 focus:ring-2 focus:ring-projector-primary-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                            <p
                                v-if="form.errors.content"
                                class="text-xs text-red-500"
                            >
                                {{ form.errors.content }}
                            </p>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <Button
                                type="button"
                                @click="toggleEdit"
                                class="rounded-xl border border-projector-primary-600 bg-white px-6 font-bold text-projector-primary-600 hover:bg-projector-primary-50 dark:border-projector-primary-400 dark:bg-transparent dark:text-projector-primary-400 dark:hover:bg-projector-primary-950/30"
                                >Cancel</Button
                            >
                            <Button
                                type="submit"
                                :disabled="form.processing"
                                class="rounded-xl bg-projector-primary-600 px-6 font-bold text-white hover:bg-projector-primary-700"
                            >
                                {{
                                    form.processing
                                        ? 'Saving...'
                                        : 'Save Changes'
                                }}
                            </Button>
                        </div>
                    </form>
                </div>

                <div
                    v-else
                    class="max-w-3xl space-y-4 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <div>
                        <p
                            class="mb-1 text-[10px] font-black tracking-widest text-projector-primary-500 uppercase"
                        >
                            Status Meeting
                        </p>
                        <h1
                            class="text-2xl font-black tracking-tight text-slate-900 dark:text-white"
                        >
                            {{ item.name }}
                        </h1>
                        <p
                            v-if="item.creator"
                            class="mt-1 text-xs text-slate-400"
                        >
                            Created by {{ item.creator.name }}
                        </p>
                    </div>
                    <div
                        class="prose prose-slate dark:prose-invert max-w-none text-[15px] leading-relaxed whitespace-pre-wrap text-slate-900 dark:text-slate-400"
                        v-html="sanitize(item.content)"
                    />
                </div>
            </div>

            <!-- Recordings Tab -->
            <div v-show="activeTab === 'recordings'">
                <div
                    v-if="!meetingProvider"
                    class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 py-16 dark:border-gray-700"
                >
                    <p class="text-sm font-bold text-gray-500">
                        No meeting provider configured
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        Configure a provider in Organization Settings to import
                        recordings.
                    </p>
                </div>

                <Deferred v-else data="recordingsData">
                    <template #fallback>
                        <div class="grid gap-0.5">
                            <div
                                v-for="i in 4"
                                :key="i"
                                class="flex h-12 animate-pulse items-center gap-3 px-2"
                            >
                                <div
                                    class="h-3.5 w-3.5 shrink-0 rounded bg-gray-100 dark:bg-gray-800"
                                />
                                <div class="flex flex-1 items-center gap-3">
                                    <div
                                        class="h-3 w-40 rounded bg-gray-100 dark:bg-gray-800"
                                    />
                                    <div
                                        class="h-2.5 w-24 rounded bg-gray-100 dark:bg-gray-800"
                                    />
                                </div>
                                <div
                                    class="h-8 w-20 rounded-md bg-gray-100 dark:bg-gray-800"
                                />
                            </div>
                        </div>
                    </template>

                    <AvailableOrgRecordings
                        :organization-id="organization.id"
                        :org-document-id="item.id"
                        :recordings="recordingsData.recordings"
                        :imported-ids="recordingsData.importedIds"
                        :can-manage="canManage"
                        :provider-error="recordingsData.providerError"
                    />
                </Deferred>
            </div>
        </div>

        <ConfirmDeleteModal
            :open="isDeleteModalOpen"
            title="Delete Status Meeting"
            :description="`Are you sure you want to delete '${item.name}'? This action cannot be undone.`"
            :loading="isDeleting"
            @confirm="handleDelete"
            @close="isDeleteModalOpen = false"
        />
    </AppLayout>
</template>
