<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Search, FileText, FileSpreadsheet, FileType, Table2, FileStack } from 'lucide-vue-next';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import TaskSearchForm, { type TaskSearchFilters } from '@/components/reports/TaskSearchForm.vue';
import TaskReportTable, { type TaskReportRow, type SortKey, type SortDir } from '@/components/reports/TaskReportTable.vue';
import {
    projectTasks,
    exportTasksPdf,
    exportTasksWord,
    exportTasksExcel,
    exportTasksGoogleSheet,
    exportTasksGoogleDoc,
    taskFilterPreferences,
    updateTaskFilterPreferences,
    destroyTaskFilterPreferences,
} from '@/actions/App/Http/Controllers/ReportController';

const props = defineProps<{
    project: Project;
}>();

// Same source as the document sidebar's own Internal/External Due Date split (see
// Documents/Show.vue) — an org-wide setting, not something derived from this project.
const page = usePage();
const usesExternalDueDates = computed(() => (page.props as any).orgMembership?.uses_external_due_dates ?? false);

// Reports always span this project plus its direct sub-projects (mirroring the calendar's
// own behavior — see Project::calendarItems()), so the Project column/filter only need to
// exist at all once there's more than just this one project in scope.
const projectOptions = computed(() => [
    { id: props.project.id, name: props.project.name },
    ...(props.project.children ?? []).map((child) => ({ id: child.id, name: child.name })),
]);
const hasSubprojects = computed(() => projectOptions.value.length > 1);

const results = ref<TaskReportRow[]>([]);
const loading = ref(false);
const hasSearched = ref(false);
const error = ref<string | null>(null);
const includeDetails = ref(false);

// The exact params sent to the last search — reused for the export links so they always
// match what's currently on screen, not whatever the form happens to hold right now.
const activeParams = ref<Record<string, string | string[]>>({});

// Mirrors TaskReportTable's own default (due_at/asc) so an export triggered before the
// user ever clicks a column header still matches what's on screen.
const currentSort = ref<{ key: SortKey; dir: SortDir }>({ key: 'due_at', dir: 'asc' });
const onSortChange = (key: SortKey, dir: SortDir) => {
    currentSort.value = { key, dir };
};

const ARRAY_FILTER_KEYS = ['assignee', 'task_status', 'priority', 'project_id', 'category_id'] as const;
const STRING_FILTER_KEYS = ['due_from', 'due_to'] as const;

// The results/hasSearched state above is local to this component instance, which doesn't
// survive navigating away (e.g. clicking a row) and back — that's a fresh mount, same as
// any other Inertia page visit. Keeping the active filters in the URL (mirroring this
// app's existing ?tab=/?from= conventions) and re-running the search from them on mount
// is what makes "search, click into a task, go back" land on the same results instead of
// the empty prompt state.
//
// `searched` is a dedicated marker rather than inferring "a search happened" from the
// filter params alone — a search with every filter left on its default (Anyone/Any
// Status/Any Priority/no dates) writes no filter params to the URL at all, which would
// otherwise be indistinguishable from "never searched" and silently fail to restore.
const filtersFromUrl = (): TaskSearchFilters | null => {
    const params = new URLSearchParams(window.location.search);
    if (!params.has('searched')) return null;

    return {
        assignee: params.getAll('assignee'),
        task_status: params.getAll('task_status'),
        priority: params.getAll('priority'),
        due_from: params.get('due_from') ?? '',
        due_to: params.get('due_to') ?? '',
        project_id: params.getAll('project_id'),
        category_id: params.getAll('category_id'),
    };
};

// A filter set saved to the server (see persistFilters() below) before a field like
// category_id existed in TaskSearchFilters won't have that key in its stored JSON — normalize
// it back to the full shape here, at the one place a persisted blob enters the app, rather
// than trusting every downstream reader (this component's own updateUrlFilters() below,
// TaskSearchForm.vue's chipsFor()) to individually guard against a missing key.
const normalizeFilters = (filters: Partial<TaskSearchFilters> | null): TaskSearchFilters | null => {
    if (!filters) return null;

    return {
        assignee: filters.assignee ?? [],
        task_status: filters.task_status ?? [],
        priority: filters.priority ?? [],
        due_from: filters.due_from ?? '',
        due_to: filters.due_to ?? '',
        project_id: filters.project_id ?? [],
        category_id: filters.category_id ?? [],
    };
};

// Remembers the last filters searched with, per project, across separate *visits* — not just
// within one (URL-based restoration above already covers that) — and, unlike browser-local
// storage, across separate browsers/devices too, since it's tied to the signed-in user's
// account server-side rather than one browser's storage.
const loadPersistedFilters = async (): Promise<TaskSearchFilters | null> => {
    try {
        const response = await axios.get<{ filters: Partial<TaskSearchFilters> | null }>(taskFilterPreferences({ project: props.project.id }).url);
        return normalizeFilters(response.data.filters);
    } catch {
        // A failed fetch just means nothing gets remembered for this visit — never worth
        // failing the page over.
        return null;
    }
};

const persistFilters = async (filters: TaskSearchFilters) => {
    try {
        await axios.put(updateTaskFilterPreferences({ project: props.project.id }).url, filters);
    } catch {
        // Remembering filters is a convenience running alongside the actual search — a failed
        // save shouldn't surface as a search error.
    }
};

const clearPersistedFilters = async () => {
    try {
        await axios.delete(destroyTaskFilterPreferences({ project: props.project.id }).url);
    } catch {
        // See persistFilters().
    }
};

// The URL (a specific link, or browser back/forward within this visit) wins when present; the
// server-remembered filters are only consulted on a genuinely fresh navigation with nothing in
// the URL at all — resolved in onMounted() below, since fetching them is asynchronous. A ref
// (not a plain constant) so TaskSearchForm — which watches this prop — can pick up the
// server-fetched value once it resolves, after the form has already rendered with defaults.
const initialFilters = ref<TaskSearchFilters | null>(filtersFromUrl());

const updateUrlFilters = (filters: TaskSearchFilters) => {
    const url = new URL(window.location.href);
    url.searchParams.set('searched', '1');
    ARRAY_FILTER_KEYS.forEach((key) => {
        url.searchParams.delete(key);
        filters[key].forEach((value) => url.searchParams.append(key, value));
    });
    STRING_FILTER_KEYS.forEach((key) => {
        if (filters[key]) {
            url.searchParams.set(key, filters[key]);
        } else {
            url.searchParams.delete(key);
        }
    });
    window.history.replaceState(window.history.state, '', url);
};

const runSearch = async (filters: TaskSearchFilters) => {
    loading.value = true;
    error.value = null;
    updateUrlFilters(filters);

    // Omit unset filters entirely rather than sending empty strings — the backend's
    // validation (e.g. priority must be low/medium/high) rejects an empty string, since
    // `nullable` only exempts an actually-absent/null value, not an empty one.
    const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
    activeParams.value = params;

    try {
        const response = await axios.get<TaskReportRow[]>(projectTasks({ project: props.project.id }).url, { params });
        results.value = response.data;
        hasSearched.value = true;
    } catch {
        error.value = 'Something went wrong searching tasks. Please try again.';
    } finally {
        loading.value = false;
    }
};

const onSearch = (filters: TaskSearchFilters) => {
    void persistFilters(filters);
    void runSearch(filters);
};

// A reset still runs the same unfiltered search a plain "Search" with nothing chosen would
// (unchanged from before this had persistence at all) — the difference is it forgets what
// was remembered too, so the *next* fresh visit lands back on the empty prompt state instead
// of silently re-running an unfiltered search on your behalf.
const onReset = (filters: TaskSearchFilters) => {
    void clearPersistedFilters();
    void runSearch(filters);
};

const exportUrl = (action: typeof exportTasksPdf | typeof exportTasksWord | typeof exportTasksExcel): string => {
    const query: Record<string, string | string[]> = {
        ...activeParams.value,
        sort_by: currentSort.value.key,
        sort_dir: currentSort.value.dir,
    };
    if (includeDetails.value) {
        query.include_details = '1';
    }

    return action({ project: props.project.id }, { query }).url;
};

// Unlike the other three exports (plain <a href> downloads), these hit a JSON endpoint —
// they need to branch on whether the user has a connected Google account (open the created
// file) or not (send them to the OAuth connect flow first).
const exportingToGoogle = ref<'sheet' | 'doc' | null>(null);
const exportToGoogle = async (kind: 'sheet' | 'doc') => {
    exportingToGoogle.value = kind;
    error.value = null;

    const query: Record<string, string | string[]> = {
        ...activeParams.value,
        sort_by: currentSort.value.key,
        sort_dir: currentSort.value.dir,
    };
    if (includeDetails.value) {
        query.include_details = '1';
    }

    const action = kind === 'sheet' ? exportTasksGoogleSheet : exportTasksGoogleDoc;
    const label = kind === 'sheet' ? 'Google Sheets' : 'Google Docs';

    try {
        const response = await axios.get<{ url: string }>(action({ project: props.project.id }, { query }).url);
        window.open(response.data.url, '_blank');
    } catch (err) {
        if (axios.isAxiosError(err) && err.response?.status === 428 && err.response.data?.connect_url) {
            // Not connected yet — send the user through the connect flow, then bounce them
            // straight back here (with this same export re-triggered automatically) instead
            // of dropping them on the standalone Settings > Integrations page.
            const returnUrl = new URL(window.location.href);
            returnUrl.searchParams.set('google_export', kind);

            const connectUrl = new URL(err.response.data.connect_url);
            connectUrl.searchParams.set('return_to', returnUrl.pathname + returnUrl.search);

            window.location.href = connectUrl.toString();
            return;
        }
        error.value = `Something went wrong exporting to ${label}. Please try again.`;
    } finally {
        exportingToGoogle.value = null;
    }
};

// Set when landing back here after being sent through the Google connect flow mid-export
// above — resumed once the restored search results are in, then stripped from the URL so a
// refresh doesn't repeat it.
const pendingGoogleExport = new URLSearchParams(window.location.search).get('google_export');

onMounted(async () => {
    if (!initialFilters.value) {
        initialFilters.value = await loadPersistedFilters();
    }
    if (initialFilters.value) {
        await runSearch(initialFilters.value);
    }

    if (pendingGoogleExport === 'sheet' || pendingGoogleExport === 'doc') {
        const url = new URL(window.location.href);
        url.searchParams.delete('google_export');
        window.history.replaceState(window.history.state, '', url);
        void exportToGoogle(pendingGoogleExport);
    }
});
</script>

<template>
    <div class="space-y-6">
        <div class="rounded-3xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-white/5 p-6">
            <TaskSearchForm
                :users="project.client.organization?.users"
                :invitations="project.client.organization?.invitations"
                :columns="project.kanban_columns"
                :project-options="projectOptions"
                :categories="project.categories"
                :initial-filters="initialFilters"
                :loading="loading"
                @search="onSearch"
                @reset="onReset"
            />
        </div>

        <p v-if="error" class="text-sm font-medium text-red-600">{{ error }}</p>

        <template v-if="hasSearched">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <Checkbox id="report-include-details" v-model="includeDetails" />
                    <Label for="report-include-details" class="text-[13px] font-medium text-slate-600 dark:text-slate-300">
                        Include task details column in export
                    </Label>
                </div>

                <div class="flex gap-2">
                    <a
                        :href="exportUrl(exportTasksExcel)"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md border border-slate-200 dark:border-white/10 text-[13px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-colors"
                    >
                        <FileSpreadsheet class="h-3.5 w-3.5" />
                        Excel
                    </a>
                    <a
                        :href="exportUrl(exportTasksWord)"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md border border-slate-200 dark:border-white/10 text-[13px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-colors"
                    >
                        <FileType class="h-3.5 w-3.5" />
                        Word
                    </a>
                    <a
                        :href="exportUrl(exportTasksPdf)"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md border border-slate-200 dark:border-white/10 text-[13px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-colors"
                    >
                        <FileText class="h-3.5 w-3.5" />
                        PDF
                    </a>
                    <button
                        type="button"
                        :disabled="exportingToGoogle !== null"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md border border-slate-200 dark:border-white/10 text-[13px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-colors disabled:opacity-50"
                        @click="exportToGoogle('sheet')"
                    >
                        <Table2 class="h-3.5 w-3.5" />
                        {{ exportingToGoogle === 'sheet' ? 'Exporting…' : 'Google Sheets' }}
                    </button>
                    <button
                        type="button"
                        :disabled="exportingToGoogle !== null"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md border border-slate-200 dark:border-white/10 text-[13px] font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-colors disabled:opacity-50"
                        @click="exportToGoogle('doc')"
                    >
                        <FileStack class="h-3.5 w-3.5" />
                        {{ exportingToGoogle === 'doc' ? 'Exporting…' : 'Google Docs' }}
                    </button>
                </div>
            </div>

            <TaskReportTable
                :tasks="results"
                :columns="project.kanban_columns"
                :uses-external-due-dates="usesExternalDueDates"
                :has-subprojects="hasSubprojects"
                @sort-change="onSortChange"
            />
        </template>

        <div
            v-else
            class="flex flex-col items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-slate-200 dark:border-slate-800 py-20"
        >
            <Search class="h-6 w-6 text-slate-300" />
            <p class="text-slate-400 font-medium text-sm">Set your filters and search to see matching tasks.</p>
        </div>
    </div>
</template>
