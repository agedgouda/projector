<script setup lang="ts">
import IconTile from '@/components/IconTile.vue';
import ResourceList from '@/components/ResourceList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    FLAT_ROW_HOVER,
    FLAT_SEARCH_ICON,
    FLAT_SEARCH_INPUT,
} from '@/lib/flat-ui';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Briefcase,
    Calendar,
    Camera,
    Code,
    Copy,
    Database,
    Edit2,
    Globe,
    Heart,
    Info,
    Layers,
    Layout,
    Megaphone,
    Microscope,
    Music,
    PenTool,
    Plus,
    Rocket,
    Search,
    Settings,
    Zap,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

// UI Components
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { duplicate as duplicateProjectType } from '@/routes/project-types/index';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    projectTypes: any[];
    aiTemplates: { id: string; name: string }[];
    organizations: { id: string; name: string }[];
}>();

const page = usePage<AppPageProps>();
const isSuperAdmin = computed(
    () => page.props.auth.user?.roles?.includes('super-admin') ?? false,
);
const isOrgAdmin = computed(
    () => page.props.auth.user?.roles?.includes('org-admin') ?? false,
);
const activeOrgId = computed(() => page.props.auth.active_org_id);

const canEditType = (type: any) => {
    if (isSuperAdmin.value) return true;
    if (isOrgAdmin.value && type.organization_id === activeOrgId.value)
        return true;
    return false;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pipelines', href: '/project-types' },
];

// --- Search Logic ---
const searchQuery = ref('');
const filteredProjectTypes = computed(() => {
    let list = props.projectTypes;
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        list = list.filter(
            (type) =>
                type.name.toLowerCase().includes(query) ||
                type.document_schema?.some((doc: any) =>
                    doc.label.toLowerCase().includes(query),
                ),
        );
    }
    return list.map((t) => ({ ...t, domId: `type-${t.id}` }));
});

const orgTypes = computed(() =>
    filteredProjectTypes.value.filter((t) => t.organization_id),
);
const globalTypes = computed(() =>
    filteredProjectTypes.value.filter((t) => !t.organization_id),
);

// --- Icon Mapping ---
const iconLibrary = [
    { name: 'Code', component: Code },
    { name: 'Megaphone', component: Megaphone },
    { name: 'Calendar', component: Calendar },
    { name: 'Layout', component: Layout },
    { name: 'Database', component: Database },
    { name: 'Globe', component: Globe },
    { name: 'Settings', component: Settings },
    { name: 'PenTool', component: PenTool },
    { name: 'Rocket', component: Rocket },
    { name: 'Microscope', component: Microscope },
    { name: 'Briefcase', component: Briefcase },
    { name: 'Music', component: Music },
    { name: 'Camera', component: Camera },
    { name: 'Zap', component: Zap },
    { name: 'Heart', component: Heart },
];

const getIcon = (name: string) =>
    iconLibrary.find((i) => i.name === name)?.component || Info;

// --- Duplicate Logic ---
const duplicateTargetOrgId = ref<Record<string, string>>({});

const duplicateType = (typeId: string, orgId?: string) => {
    const targetOrg = orgId ?? duplicateTargetOrgId.value[typeId];
    if (!targetOrg) return;

    router.post(duplicateProjectType.url(typeId), {
        organization_id: targetOrg,
    });
};
</script>

<template>
    <Head title="Pipelines" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full p-6">
            <div
                class="mb-12 flex flex-col items-start justify-between gap-6 lg:flex-row lg:items-center"
            >
                <div>
                    <h1
                        class="flex items-center gap-3 text-2xl font-black tracking-tighter text-gray-900 uppercase dark:text-white"
                    >
                        <Layers class="h-7 w-7 text-projector-primary-600" />
                        Pipelines
                    </h1>
                    <p class="text-sm font-medium text-gray-500">
                        Define the document structures and AI logic for your
                        project types.
                    </p>
                </div>

                <div
                    class="flex w-full flex-col items-center gap-3 sm:flex-row lg:w-auto"
                >
                    <div class="group relative w-full sm:w-72">
                        <Search :class="FLAT_SEARCH_ICON" />
                        <Input
                            v-model="searchQuery"
                            placeholder="Filter pipelines..."
                            :class="FLAT_SEARCH_INPUT"
                        />
                    </div>

                    <Link href="/project-types/create">
                        <Button class="h-12 w-full px-8 sm:w-auto">
                            <Plus class="mr-2 h-4 w-4" />
                            New Pipeline
                        </Button>
                    </Link>
                </div>
            </div>

            <div class="relative space-y-10">
                <div
                    v-if="filteredProjectTypes.length === 0"
                    class="rounded-[3rem] border-2 border-dashed border-gray-100 py-24 text-center dark:border-gray-800"
                >
                    <Search class="mx-auto mb-4 h-10 w-10 text-gray-200" />
                    <h3
                        class="text-xs font-black tracking-widest text-gray-400 uppercase"
                    >
                        No matching project types found
                    </h3>
                </div>

                <!-- Org-specific section -->
                <div v-if="orgTypes.length > 0">
                    <div class="mb-4 flex items-center gap-3">
                        <h2
                            class="text-[10px] font-black tracking-[0.2em] text-projector-primary-600 uppercase dark:text-projector-primary-400"
                        >
                            {{
                                isSuperAdmin
                                    ? 'Organization Types'
                                    : 'My Organization'
                            }}
                        </h2>
                        <span
                            class="text-[9px] font-black text-gray-400 dark:text-gray-600"
                            >{{ orgTypes.length }}</span
                        >
                    </div>
                    <ResourceList :items="orgTypes">
                        <template #default="{ item: type }">
                            <div
                                :class="[
                                    'space-y-4 rounded-md p-3 transition-colors',
                                    FLAT_ROW_HOVER,
                                ]"
                            >
                                <div class="flex items-center gap-3 px-1">
                                    <IconTile
                                        :icon="getIcon(type.icon)"
                                        size="md"
                                    />
                                    <div
                                        class="flex min-w-0 flex-1 flex-wrap items-center gap-2"
                                    >
                                        <h4
                                            class="truncate text-[13px] font-bold text-slate-900 dark:text-slate-100"
                                        >
                                            {{ type.name }}
                                        </h4>
                                        <!-- Super-admin sees org name badge since they see all orgs -->
                                        <span
                                            v-if="
                                                isSuperAdmin &&
                                                type.organization
                                            "
                                            class="rounded-md border border-projector-primary-100 bg-projector-primary-50 px-2 py-0.5 text-[9px] font-black tracking-widest text-projector-primary-600 uppercase dark:border-projector-primary-500/20 dark:bg-projector-primary-500/10 dark:text-projector-primary-400"
                                        >
                                            {{ type.organization.name }}
                                        </span>
                                    </div>
                                    <Link
                                        v-if="canEditType(type)"
                                        :href="`/project-types/${type.id}/edit`"
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-projector-primary-50 hover:text-projector-primary-600 dark:hover:bg-projector-primary-950/30"
                                        title="Edit project type"
                                    >
                                        <Edit2 class="h-3.5 w-3.5" />
                                    </Link>
                                </div>

                                <div class="pl-1">
                                    <p
                                        class="mb-2 px-1 text-[8px] font-black tracking-[0.2em] text-gray-400 uppercase"
                                    >
                                        Structure
                                    </p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="doc in type.document_schema"
                                            :key="doc.key"
                                            class="flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[10px] font-bold transition-all"
                                            :class="
                                                doc.required
                                                    ? 'border-projector-primary-100 bg-projector-primary-50/50 text-projector-primary-700 dark:border-projector-primary-500/20 dark:bg-projector-primary-500/5 dark:text-projector-primary-400'
                                                    : 'border-gray-100 bg-white text-gray-500 dark:border-gray-800 dark:bg-gray-950'
                                            "
                                        >
                                            <div
                                                v-if="doc.required"
                                                class="h-1 w-1 rounded-full bg-projector-primary-500 shadow-[0_0_5px_rgba(99,102,241,0.5)]"
                                            ></div>
                                            {{ doc.label }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="type.workflow?.length" class="pl-1">
                                    <p
                                        class="mb-2 px-1 text-[8px] font-black tracking-[0.2em] text-gray-400 uppercase"
                                    >
                                        Automated Pipeline
                                    </p>
                                    <div
                                        class="flex flex-wrap items-center gap-x-4 gap-y-2"
                                    >
                                        <div
                                            v-for="(step, idx) in type.workflow"
                                            :key="idx"
                                            class="flex items-center gap-2"
                                        >
                                            <span
                                                class="text-[10px] font-black tracking-tighter text-gray-600 uppercase dark:text-gray-300"
                                                >{{ step.from_key }}</span
                                            >
                                            <div
                                                class="flex flex-col items-center"
                                            >
                                                <Zap
                                                    class="mb-0.5 h-2.5 w-2.5 text-amber-500"
                                                    v-if="step.ai_template_id"
                                                />
                                                <ArrowRight
                                                    class="h-3 text-gray-500"
                                                />
                                            </div>
                                            <span
                                                class="text-[10px] font-black tracking-tighter text-projector-primary-600 uppercase dark:text-projector-primary-400"
                                                >{{ step.to_key }}</span
                                            >
                                            <div
                                                v-if="
                                                    Number(idx) <
                                                    type.workflow.length - 1
                                                "
                                                class="ml-2 h-1 w-1 rounded-full bg-gray-200 dark:bg-gray-800"
                                            ></div>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="isSuperAdmin"
                                    class="flex items-center gap-2 border-t border-gray-100 px-1 pt-2 dark:border-gray-800"
                                >
                                    <select
                                        v-model="duplicateTargetOrgId[type.id]"
                                        class="h-8 flex-1 rounded-lg border border-gray-200 bg-white px-3 text-[11px] text-gray-600 outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400"
                                    >
                                        <option value="">
                                            Select org to duplicate into...
                                        </option>
                                        <option
                                            v-for="org in organizations"
                                            :key="org.id"
                                            :value="org.id"
                                        >
                                            {{ org.name }}
                                        </option>
                                    </select>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="
                                            !duplicateTargetOrgId[type.id]
                                        "
                                        @click="duplicateType(type.id)"
                                        class="h-8 px-3 text-[10px] font-black text-projector-primary-600 uppercase hover:bg-projector-primary-50 disabled:opacity-40 dark:hover:bg-projector-primary-500/10"
                                    >
                                        <Copy class="mr-1 h-3 w-3" /> Duplicate
                                    </Button>
                                </div>
                            </div>
                        </template>
                    </ResourceList>
                </div>

                <!-- Global templates section -->
                <div v-if="globalTypes.length > 0">
                    <div class="mb-4 flex items-center gap-3">
                        <h2
                            class="text-[10px] font-black tracking-[0.2em] text-gray-400 uppercase dark:text-gray-500"
                        >
                            Global Templates
                        </h2>
                        <span
                            class="text-[9px] font-black text-gray-400 dark:text-gray-600"
                            >{{ globalTypes.length }}</span
                        >
                    </div>
                    <ResourceList :items="globalTypes">
                        <template #default="{ item: type }">
                            <div
                                :class="[
                                    'space-y-4 rounded-md p-3 transition-colors',
                                    FLAT_ROW_HOVER,
                                ]"
                            >
                                <div class="flex items-center gap-3 px-1">
                                    <IconTile
                                        :icon="getIcon(type.icon)"
                                        size="md"
                                    />
                                    <div
                                        class="flex min-w-0 flex-1 flex-wrap items-center gap-2"
                                    >
                                        <h4
                                            class="truncate text-[13px] font-bold text-slate-900 dark:text-slate-100"
                                        >
                                            {{ type.name }}
                                        </h4>
                                    </div>
                                    <Link
                                        v-if="canEditType(type)"
                                        :href="`/project-types/${type.id}/edit`"
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-projector-primary-50 hover:text-projector-primary-600 dark:hover:bg-projector-primary-950/30"
                                        title="Edit project type"
                                    >
                                        <Edit2 class="h-3.5 w-3.5" />
                                    </Link>
                                </div>

                                <div class="pl-1">
                                    <p
                                        class="mb-2 px-1 text-[8px] font-black tracking-[0.2em] text-gray-400 uppercase"
                                    >
                                        Structure
                                    </p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="doc in type.document_schema"
                                            :key="doc.key"
                                            class="flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[10px] font-bold transition-all"
                                            :class="
                                                doc.required
                                                    ? 'border-projector-primary-100 bg-projector-primary-50/50 text-projector-primary-700 dark:border-projector-primary-500/20 dark:bg-projector-primary-500/5 dark:text-projector-primary-400'
                                                    : 'border-gray-100 bg-white text-gray-500 dark:border-gray-800 dark:bg-gray-950'
                                            "
                                        >
                                            <div
                                                v-if="doc.required"
                                                class="h-1 w-1 rounded-full bg-projector-primary-500 shadow-[0_0_5px_rgba(99,102,241,0.5)]"
                                            ></div>
                                            {{ doc.label }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="type.workflow?.length" class="pl-1">
                                    <p
                                        class="mb-2 px-1 text-[8px] font-black tracking-[0.2em] text-gray-400 uppercase"
                                    >
                                        Automated Pipeline
                                    </p>
                                    <div
                                        class="flex flex-wrap items-center gap-x-4 gap-y-2"
                                    >
                                        <div
                                            v-for="(step, idx) in type.workflow"
                                            :key="idx"
                                            class="flex items-center gap-2"
                                        >
                                            <span
                                                class="text-[10px] font-black tracking-tighter text-gray-600 uppercase dark:text-gray-300"
                                                >{{ step.from_key }}</span
                                            >
                                            <div
                                                class="flex flex-col items-center"
                                            >
                                                <Zap
                                                    class="mb-0.5 h-2.5 w-2.5 text-amber-500"
                                                    v-if="step.ai_template_id"
                                                />
                                                <ArrowRight
                                                    class="h-3 text-gray-500"
                                                />
                                            </div>
                                            <span
                                                class="text-[10px] font-black tracking-tighter text-projector-primary-600 uppercase dark:text-projector-primary-400"
                                                >{{ step.to_key }}</span
                                            >
                                            <div
                                                v-if="
                                                    Number(idx) <
                                                    type.workflow.length - 1
                                                "
                                                class="ml-2 h-1 w-1 rounded-full bg-gray-200 dark:bg-gray-800"
                                            ></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Super-admin: duplicate global type into an org -->
                                <div
                                    v-if="isSuperAdmin"
                                    class="flex items-center gap-2 border-t border-gray-100 px-1 pt-2 dark:border-gray-800"
                                >
                                    <select
                                        v-model="duplicateTargetOrgId[type.id]"
                                        class="h-8 flex-1 rounded-lg border border-gray-200 bg-white px-3 text-[11px] text-gray-600 outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400"
                                    >
                                        <option value="">
                                            Select org to duplicate into...
                                        </option>
                                        <option
                                            v-for="org in organizations"
                                            :key="org.id"
                                            :value="org.id"
                                        >
                                            {{ org.name }}
                                        </option>
                                    </select>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="
                                            !duplicateTargetOrgId[type.id]
                                        "
                                        @click="duplicateType(type.id)"
                                        class="h-8 px-3 text-[10px] font-black text-projector-primary-600 uppercase hover:bg-projector-primary-50 disabled:opacity-40 dark:hover:bg-projector-primary-500/10"
                                    >
                                        <Copy class="mr-1 h-3 w-3" /> Duplicate
                                    </Button>
                                </div>
                                <!-- Org-admin: copy global type into their org -->
                                <div
                                    v-else-if="isOrgAdmin"
                                    class="border-t border-gray-100 px-1 pt-2 dark:border-gray-800"
                                >
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            duplicateType(
                                                type.id,
                                                activeOrgId ?? undefined,
                                            )
                                        "
                                        class="h-8 px-3 text-[10px] font-black text-projector-primary-600 uppercase hover:bg-projector-primary-50 dark:hover:bg-projector-primary-500/10"
                                    >
                                        <Copy class="mr-1 h-3 w-3" /> Copy to My
                                        Org
                                    </Button>
                                </div>
                            </div>
                        </template>
                    </ResourceList>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
