<script setup lang="ts">
import NewProjectModal from '@/components/projects/NewProjectModal.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import orgDocumentsDraftRoutes from '@/routes/organizations/documents/draft/index';
import statusMeetingsRoutes from '@/routes/status-meetings/index';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import { ArrowLeft, Check, Edit2, Loader2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

interface DraftGroup {
    group_id: string;
    project_id: string | null;
    project_name: string;
    client_name: string;
    is_new: boolean;
    document_title: string;
    document_content: string;
}

interface ActiveProject {
    id: string;
    name: string;
    client_name: string;
}

const props = defineProps<{
    organization: { id: string; name: string };
    orgDocument: { id: string; name: string };
    group: DraftGroup;
    canManage: boolean;
    activeProjects: ActiveProject[];
    clients: Client[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Status Meetings',
        href: statusMeetingsRoutes.index.url({
            query: { expand: props.orgDocument.id },
        }),
    },
    { title: props.orgDocument.name, href: '#' },
];

const isEditing = ref(false);
const isCommitting = ref(false);

const localGroup = ref<DraftGroup>(JSON.parse(JSON.stringify(props.group)));

const canCommit = computed(
    () =>
        localGroup.value.project_id !== null &&
        localGroup.value.document_content.trim() !== '',
);

const sanitize = (html: string) => DOMPurify.sanitize(html);

const handleProjectCreated = () => {
    router.reload({
        only: ['activeProjects'],
        onSuccess: () => {
            const match = props.activeProjects.find(
                (p) =>
                    p.name.toLowerCase() ===
                    localGroup.value.project_name.toLowerCase(),
            );
            if (match) {
                localGroup.value.project_id = match.id;
            }
        },
    });
};

const commit = () => {
    if (!canCommit.value) {
        return;
    }
    isCommitting.value = true;
    router.post(
        orgDocumentsDraftRoutes.commit({
            organization: props.organization.id,
            orgDocument: props.orgDocument.id,
            groupId: props.group.group_id,
        }).url,
        {
            project_id: localGroup.value.project_id,
            document_title: localGroup.value.document_title,
            document_content: localGroup.value.document_content,
        },
        {
            onError: (errors) =>
                toast.error(Object.values(errors)[0] as string),
            onFinish: () => {
                isCommitting.value = false;
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
</script>

<template>
    <Head :title="localGroup.document_title || group.project_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-screen bg-light-background">
            <!-- Top nav bar -->
            <nav
                class="border-b bg-light-background px-6 py-4 dark:border-gray-800"
            >
                <div class="flex items-center justify-between">
                    <button
                        @click="
                            router.visit(
                                statusMeetingsRoutes.index.url({
                                    query: { expand: props.orgDocument.id },
                                }),
                            )
                        "
                        class="flex cursor-pointer items-center gap-2 border-0 bg-transparent p-0 text-sm text-slate-500 transition-colors hover:text-projector-primary-600"
                    >
                        <ArrowLeft class="h-3 w-3" />
                        {{ orgDocument.name }}
                    </button>

                    <div v-if="canManage" class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="isEditing = !isEditing"
                            class="relative flex h-8 w-24 items-center justify-center text-[10px] font-black tracking-widest uppercase transition-all duration-200"
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
                    </div>
                </div>
            </nav>

            <main class="w-full px-6 py-6">
                <div class="grid grid-cols-12 gap-12">
                    <!-- Main content -->
                    <div class="col-span-12 space-y-4 lg:col-span-8">
                        <!-- Unmatched project warning -->
                        <div
                            v-if="!localGroup.project_id"
                            class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 dark:border-amber-800 dark:bg-amber-950/30"
                        >
                            <p
                                class="text-xs font-black tracking-widest text-amber-600 uppercase"
                            >
                                Project Not Matched
                            </p>
                            <p
                                class="text-sm text-amber-700 dark:text-amber-300"
                            >
                                This document was extracted for
                                <strong>{{ localGroup.project_name }}</strong>
                                <template v-if="localGroup.client_name">
                                    ({{ localGroup.client_name }})</template
                                >, but no matching project was found. Assign it
                                to a project before committing.
                            </p>
                            <div class="flex flex-wrap items-center gap-3">
                                <select
                                    class="h-9 rounded-lg border border-amber-200 bg-white px-3 text-sm font-medium text-gray-700 focus:ring-2 focus:ring-amber-400 focus:outline-none dark:border-amber-800 dark:bg-zinc-900 dark:text-gray-300"
                                    @change="
                                        (e) => {
                                            localGroup.project_id =
                                                (e.target as HTMLSelectElement)
                                                    .value || null;
                                            localGroup.is_new = false;
                                        }
                                    "
                                >
                                    <option value="">Assign to project…</option>
                                    <option
                                        v-for="p in activeProjects"
                                        :key="p.id"
                                        :value="p.id"
                                    >
                                        {{ p.name }} ({{ p.client_name }})
                                    </option>
                                </select>
                                <NewProjectModal
                                    v-if="canManage"
                                    :clients="clients"
                                    :initial-name="localGroup.project_name"
                                    trigger-label="Create Project"
                                    @success="handleProjectCreated"
                                >
                                    <template #trigger>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            class="h-9 rounded-lg px-3 text-[10px] font-black tracking-widest text-projector-primary-600 uppercase hover:bg-projector-primary-50 dark:hover:bg-projector-primary-950"
                                        >
                                            <span
                                                class="flex items-center gap-1"
                                            >
                                                <svg
                                                    class="h-3 w-3"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                    stroke-width="2.5"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M12 4v16m8-8H4"
                                                    />
                                                </svg>
                                                Create Project
                                            </span>
                                        </Button>
                                    </template>
                                </NewProjectModal>
                            </div>
                        </div>

                        <!-- Document card -->
                        <div
                            class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <!-- Edit mode -->
                            <div v-if="isEditing" class="space-y-4 p-6">
                                <div class="space-y-1.5">
                                    <label
                                        class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                                        >Title</label
                                    >
                                    <input
                                        v-model="localGroup.document_title"
                                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-900 focus:ring-2 focus:ring-projector-primary-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                        placeholder="Document title"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        class="text-[11px] font-black tracking-widest text-slate-500 uppercase"
                                        >Content (HTML)</label
                                    >
                                    <textarea
                                        v-model="localGroup.document_content"
                                        rows="20"
                                        class="w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-xs leading-relaxed text-slate-700 focus:ring-2 focus:ring-projector-primary-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-slate-300"
                                    />
                                </div>
                                <div class="flex justify-end">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="isEditing = false"
                                    >
                                        <Check class="mr-1.5 h-3 w-3" /> Done
                                    </Button>
                                </div>
                            </div>

                            <!-- Preview mode -->
                            <div
                                v-else
                                class="prose-content px-8 py-6"
                                v-html="sanitize(localGroup.document_content)"
                            />
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <aside class="col-span-12 space-y-4 lg:col-span-4">
                        <!-- Draft badge -->
                        <div
                            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30"
                        >
                            <p
                                class="mb-0.5 text-[10px] font-black tracking-widest text-amber-600 uppercase"
                            >
                                Status
                            </p>
                            <p
                                class="text-sm font-bold text-amber-700 dark:text-amber-300"
                            >
                                Draft — Pending Review
                            </p>
                        </div>

                        <!-- Project info -->
                        <div
                            v-if="localGroup.project_id"
                            class="space-y-1 rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <p
                                class="text-[10px] font-black tracking-widest text-slate-400 uppercase"
                            >
                                Project
                            </p>
                            <p
                                class="text-sm font-bold text-slate-900 dark:text-white"
                            >
                                {{ localGroup.project_name }}
                            </p>
                            <p
                                v-if="localGroup.client_name"
                                class="text-xs text-slate-400"
                            >
                                {{ localGroup.client_name }}
                            </p>
                        </div>

                        <!-- Commit button -->
                        <Button
                            v-if="canManage"
                            :disabled="!canCommit || isCommitting"
                            @click="commit"
                            class="h-11 w-full rounded-xl bg-projector-primary-600 text-[10px] font-black tracking-widest text-white uppercase hover:bg-projector-primary-700"
                        >
                            <Loader2
                                v-if="isCommitting"
                                class="mr-2 h-3 w-3 animate-spin"
                            />
                            <Check v-else class="mr-2 h-3 w-3" />
                            {{
                                isCommitting
                                    ? 'Committing…'
                                    : 'Commit to Project'
                            }}
                        </Button>
                    </aside>
                </div>
            </main>
        </div>
    </AppLayout>
</template>
