<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Coffee } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectSummaryCard from '@/components/dashboard/ProjectSummaryCard.vue';
import projectRoutes from '@/routes/projects/index';

// Demo of dashboard redesign "option 2", now iterated to: one card per project/subproject
// family, each starting collapsed to just its combined pie chart of not-done tasks — expanding
// (ProjectSummaryCard.vue's own local state) reveals the per-subproject breakdown and both
// deliverable lists in place, instead of a kanban board or a trip to the full project page.
// Design iteration in progress per the user's request — not yet verified with
// tests/typecheck/browser, intentionally, until the design settles.
const props = defineProps<{
    projects: Project[];
    kanbanData: Record<string, ProjectDocument[]>;
    clients: Client[];
    currentOrganization: { id: string; name: string } | null;
    canViewProjectDetails: boolean;
}>();

const currentUserId = usePage<{ auth: { user: { id: number } | null } }>().props.auth.user?.id ?? null;

const breadcrumbs = computed(() => [
    {
        title: props.currentOrganization
            ? `Dashboard 2 (Demo) ${props.currentOrganization.name}`
            : 'Dashboard 2 (Demo)',
        href: '/dashboard2',
    },
]);

// Kanban column colors are Tailwind color names (see KanbanColumn::defaultDefinitions()) —
// the pie chart needs real CSS colors for its conic-gradient, so this maps the same palette
// kanbanDotClasses (lib/constants.ts) uses for its dots.
const COLUMN_HEX: Record<string, string> = {
    slate: '#94a3b8',
    red: '#ef4444',
    amber: '#fbbf24',
    emerald: '#10b981',
    blue: '#3b82f6',
    purple: '#a855f7',
    pink: '#ec4899',
    orange: '#f97316',
    indigo: '#6366f1',
    teal: '#14b8a6',
};

interface Family {
    key: string;
    label: string;
    members: Project[];
}

// A "family" is a top-level project plus its direct children — mirrors Project::familyRoot()/
// familyProjectIds() on the backend. A project whose parent isn't in this org's project list
// (shouldn't normally happen) is treated as its own top-level family rather than dropped.
const families = computed<Family[]>(() => {
    const byId = new Map(props.projects.map((p) => [p.id, p]));
    const tops = props.projects.filter((p) => !p.parent_id || !byId.has(p.parent_id));

    return tops.map((top) => ({
        key: top.id,
        label: top.name,
        members: [top, ...props.projects.filter((p) => p.parent_id === top.id)],
    }));
});

// Not-yet-done tasks (task_status !== 'done'), broken down by status, across one or more
// projects — used both for a single project's own pie and for a whole family's combined pie.
// Segments are keyed by column key so two subprojects with matching columns (the normal case —
// see Project::hasMatchingKanbanColumns()) merge into one slice instead of duplicating.
function statusBreakdown(members: Project[]) {
    const byKey = new Map<string, { label: string; color: string; count: number }>();

    for (const member of members) {
        for (const column of member.kanban_columns ?? []) {
            if (column.key === 'done') continue;
            if (!byKey.has(column.key)) {
                byKey.set(column.key, { label: column.label, color: COLUMN_HEX[column.color ?? 'slate'] ?? '#94a3b8', count: 0 });
            }
        }
    }

    for (const member of members) {
        for (const task of props.kanbanData[member.id] ?? []) {
            if (task.task_status === 'done') continue;
            const entry = byKey.get(task.task_status);
            if (entry) {
                entry.count += 1;
            } else {
                byKey.set(task.task_status, { label: task.task_status, color: '#94a3b8', count: 1 });
            }
        }
    }

    return Array.from(byKey.values());
}

function allDeliverables(members: Project[]) {
    return members.flatMap((member) => props.kanbanData[member.id] ?? []);
}

// No year — these cards are narrow (3-up grid), and the fixed-width date column was eating so
// much room that task names were truncating down to a handful of characters. Dropping the year
// (due dates here are always near-term) reclaims that space for the name, which is the part
// someone's actually trying to read.
function formatDate(value: string | null) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function upcomingDeliverables(members: Project[], limit = 6) {
    return allDeliverables(members)
        .filter((task) => task.due_at && task.task_status !== 'done')
        .sort((a, b) => new Date(a.due_at!).getTime() - new Date(b.due_at!).getTime())
        .slice(0, limit)
        .map((task) => ({ id: task.id, name: task.name, dueLabel: formatDate(task.due_at) }));
}

// Not-done tasks assigned to the logged-in user, across a family — replaces a flat "every
// task" list, which was more of a raw data dump than something actually useful to look at.
function yourDeliverables(members: Project[]) {
    return allDeliverables(members)
        .filter((task) => task.assignee_id === currentUserId && task.task_status !== 'done')
        .map((task) => ({ id: task.id, name: task.name, dueLabel: formatDate(task.due_at) }));
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-12 p-6">
            <div
                class="rounded-xl border border-dashed border-projector-primary-300 bg-projector-primary-50/50 px-4 py-2 text-[11px] font-bold text-projector-primary-700 dark:border-projector-primary-800 dark:bg-projector-primary-950/30 dark:text-projector-primary-300"
            >
                Demo page — one section per project/subproject family: a combined pie chart of
                not-done tasks, one broken out per subproject, then upcoming and full deliverable
                lists. Not linked from navigation.
            </div>

            <div v-if="!projects.length" class="flex min-h-[40vh] flex-col items-center justify-center">
                <div class="mb-4 rounded-full bg-gray-100 p-4">
                    <Coffee class="h-12 w-12 text-gray-400" />
                </div>
                <h2 class="text-xl font-bold text-gray-900">Coming Soon</h2>
                <p class="max-w-xs text-center text-gray-500">
                    You have not yet been assigned any projects or tasks.
                </p>
            </div>

            <!-- Grid of project cards, not one full-width section per project — lets several
                 projects show at once instead of everyone scrolling past one giant section per
                 project. Collapsed cards are compact (3 per row on wide screens); an expanded
                 card spans 2 columns (see ProjectSummaryCard.vue's own col-span classes) so it
                 gets the extra width its deliverable list needs without permanently widening
                 every other still-collapsed card. items-start keeps a row's shorter cards from
                 stretching to match a row-mate that's expanded taller. -->
            <div class="grid grid-cols-1 items-start gap-6 md:grid-cols-2 xl:grid-cols-3">
                <ProjectSummaryCard
                    v-for="family in families"
                    :key="family.key"
                    :label="family.label"
                    :view-project-url="canViewProjectDetails ? projectRoutes.show.url(family.key, { query: { tab: 'tasks' } }) : null"
                    :all-title="family.members.length > 1 ? 'All' : undefined"
                    :all-segments="statusBreakdown(family.members)"
                    :member-breakdowns="
                        family.members.length > 1
                            ? family.members.map((member) => ({ title: member.name, segments: statusBreakdown([member]) }))
                            : []
                    "
                    :upcoming="upcomingDeliverables(family.members)"
                    :yours="yourDeliverables(family.members)"
                />
            </div>
        </div>
    </AppLayout>
</template>
