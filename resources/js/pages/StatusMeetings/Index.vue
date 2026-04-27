<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, Deferred } from '@inertiajs/vue3';
import { Plus, FileText, CalendarDays } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import AvailableOrgRecordings from '@/components/AvailableOrgRecordings.vue';
import { type BreadcrumbItem } from '@/types';
import statusMeetingsRoutes from '@/routes/status-meetings/index';
import orgDocumentsRoutes from '@/routes/organizations/documents/index';

const props = defineProps<{
    currentOrg: { id: string; name: string };
    organizations: OrgOption[];
    statusMeetings: StatusMeeting[];
    canManage: boolean;
    meetingProvider: string | null;
    recordingsData: RecordingsData;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Status Meetings', href: statusMeetingsRoutes.index.url() },
];

const activeTab = ref<'documentation' | 'recordings'>('documentation');

const switchOrg = (id: string) => {
    router.get(statusMeetingsRoutes.index.url({ query: { org: id } }));
};

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
</script>

<template>
    <Head title="Status Meetings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6 space-y-8 w-full">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900 dark:text-white">Status Meetings</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Cross-project meeting notes and action items for {{ currentOrg.name }}.</p>
                </div>

                <div class="flex items-center gap-3">
                    <select
                        v-if="organizations.length > 1"
                        :value="currentOrg.id"
                        @change="switchOrg(($event.target as HTMLSelectElement).value)"
                        class="h-10 rounded-xl border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 text-sm font-medium text-slate-700 dark:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                        <option v-for="org in organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
                    </select>

                    <Button
                        v-if="canManage"
                        @click="router.visit(orgDocumentsRoutes.create({ organization: currentOrg.id }).url)"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold h-10 px-5 rounded-xl shadow-lg shadow-indigo-500/20 active:scale-95 transition-all"
                    >
                        <Plus class="w-4 h-4 mr-2" />
                        <span class="text-[10px] font-black uppercase tracking-widest">New Status Meeting</span>
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center border-b border-gray-200 dark:border-gray-700">
                <button v-for="tab in ['documentation', 'recordings']" :key="tab"
                    @click="activeTab = tab as 'documentation' | 'recordings'"
                    :class="['px-8 py-4 text-[10px] font-black uppercase tracking-[0.2em] transition-all border-b-2 -mb-[1px]',
                        activeTab === tab ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-400 hover:text-gray-600']">
                    {{ tab === 'documentation' ? 'Documentation' : 'Recordings' }}
                </button>
            </div>

            <!-- Documentation Tab -->
            <div v-show="activeTab === 'documentation'">
                <div v-if="statusMeetings.length === 0" class="text-center py-20 border-2 border-dashed rounded-3xl border-gray-100 dark:border-gray-800/50 space-y-3">
                    <div class="flex justify-center">
                        <div class="p-4 bg-gray-100 dark:bg-zinc-800 rounded-full">
                            <CalendarDays class="w-8 h-8 text-gray-400" />
                        </div>
                    </div>
                    <p class="text-gray-500 font-bold">No status meetings yet</p>
                    <p class="text-sm text-gray-400">Create a status meeting to capture notes across multiple projects.</p>
                </div>

                <div v-else class="space-y-2">
                    <a
                        v-for="meeting in statusMeetings"
                        :key="meeting.id"
                        :href="orgDocumentsRoutes.show({ organization: currentOrg.id, orgDocument: meeting.id }).url"
                        class="flex items-center justify-between p-4 bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 rounded-xl hover:border-indigo-300 dark:hover:border-indigo-700 transition-colors group shadow-sm"
                    >
                        <div class="flex items-center gap-4 min-w-0">
                            <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-indigo-50 dark:bg-indigo-500/10 shrink-0">
                                <FileText class="w-4 h-4 text-indigo-500" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-black text-slate-800 dark:text-zinc-100 truncate">{{ meeting.name }}</p>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span v-if="meeting.creator" class="text-[11px] text-slate-400">{{ meeting.creator.name }}</span>
                                    <span v-if="meeting.creator" class="text-slate-300 dark:text-zinc-600">·</span>
                                    <span class="text-[11px] text-slate-400">{{ formatDate(meeting.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                        <span class="text-[10px] font-black uppercase tracking-widest text-slate-400 group-hover:text-indigo-500 transition-colors shrink-0 ml-4">View</span>
                    </a>
                </div>
            </div>

            <!-- Recordings Tab -->
            <div v-show="activeTab === 'recordings'">
                <div v-if="!meetingProvider" class="flex flex-col items-center justify-center py-16 rounded-2xl border border-dashed border-gray-200 dark:border-gray-700">
                    <p class="text-sm font-bold text-gray-500">No meeting provider configured</p>
                    <p class="text-xs text-gray-400 mt-1">Configure a provider in Organization Settings to import recordings.</p>
                </div>

                <Deferred v-else data="recordingsData">
                    <template #fallback>
                        <div class="space-y-3">
                            <div v-for="i in 4" :key="i" class="flex items-center gap-4 p-4 rounded-2xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 animate-pulse">
                                <div class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-gray-800 shrink-0" />
                                <div class="flex-1 space-y-2">
                                    <div class="h-3 bg-gray-100 dark:bg-gray-800 rounded w-1/3" />
                                    <div class="h-2.5 bg-gray-100 dark:bg-gray-800 rounded w-1/5" />
                                </div>
                                <div class="h-9 w-24 bg-gray-100 dark:bg-gray-800 rounded-xl" />
                            </div>
                        </div>
                    </template>

                    <AvailableOrgRecordings
                        :organization-id="currentOrg.id"
                        :recordings="recordingsData.recordings"
                        :imported-ids="recordingsData.importedIds"
                        :can-manage="canManage"
                        :provider-error="recordingsData.providerError"
                    />
                </Deferred>
            </div>
        </div>
    </AppLayout>
</template>
