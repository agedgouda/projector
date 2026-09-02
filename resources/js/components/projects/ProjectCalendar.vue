<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { TAG_FILTER_NONE } from '@/composables/kanban/useKanbanQueries';
import {
    KANBAN_COLOR_PALETTE,
    kanbanClasses,
    kanbanDotClasses,
} from '@/lib/constants';
import { kanbanCardBg } from '@/lib/kanban-theme';
import { formatDateOnly } from '@/lib/utils';
import projectCalendarRoutes from '@/routes/projects/calendar';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import { router, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    FileDown,
    FileSpreadsheet,
    Sheet,
    Upload,
} from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    projectId: string;
    items: CalendarItem[];
    canManageImports: boolean;
}>();

const emit = defineEmits<{
    (e: 'import-events'): void;
}>();

const page = usePage();
// external_due_at is preferred when the org uses external due dates, but falls back to due_at
// when it's empty — most existing tasks/events (and every Event today, which has no UI to set
// external_due_at at all) only ever have due_at set, so a strict either/or (no fallback, like
// TaskRowFields.vue's dense single-field row editor) made those items vanish from the calendar
// entirely instead of just deprioritizing them.
const usesExternalDueDates = computed(
    () => (page.props as any).orgMembership?.uses_external_due_dates ?? false,
);
const effectiveDueAt = (item: CalendarItem): string | null =>
    (usesExternalDueDates.value ? item.external_due_at : null) ?? item.due_at;

interface GridDay {
    date: Date;
    dateKey: string;
    inCurrentMonth: boolean;
    isToday: boolean;
}

interface EventRange {
    item: CalendarItem;
    startKey: string;
    endKey: string;
}

// One bar is one event's visible segment within a single week row — a multi-week event
// produces a separate EventBar per week it crosses, all sharing the same `lane` (see
// eventLanes below) so it renders at the same vertical row on every day and week it spans.
interface EventBar {
    item: CalendarItem;
    lane: number;
    startCol: number;
    span: number;
    continuesBefore: boolean;
    continuesAfter: boolean;
}

interface WeekRow {
    days: GridDay[];
    bars: EventBar[];
    laneCount: number;
}

const formatDateKey = (date: Date): string => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
};

// One distinct palette color per sub-project (stable, based on first-seen order),
// so each sub-project's items are visually identifiable at a glance. The parent
// project's own items intentionally keep the plain primary-tinted background — only
// sub-projects need to be told apart from each other.
const subprojects = computed(() => {
    const seen = new Map<string, string>();
    for (const item of props.items) {
        if (item.is_subproject && !seen.has(item.project_id)) {
            seen.set(item.project_id, item.project_name);
        }
    }
    return Array.from(seen, ([id, name]) => ({ id, name })).sort((a, b) =>
        a.name.localeCompare(b.name),
    );
});

const subprojectColors = computed(() => {
    const map: Record<string, string> = {};
    subprojects.value.forEach((sp, i) => {
        map[sp.id] = KANBAN_COLOR_PALETTE[i % KANBAN_COLOR_PALETTE.length];
    });
    return map;
});

// Which sub-projects are filtered OUT of the calendar. Empty by default — everything
// shows until the user hides something. The parent project's own items are never
// filterable, only its sub-projects'.
const hiddenSubprojectIds = reactive(new Set<string>());
const toggleSubproject = (id: string) => {
    if (hiddenSubprojectIds.has(id)) {
        hiddenSubprojectIds.delete(id);
    } else {
        hiddenSubprojectIds.add(id);
    }
};

// Same multi-select/OR convention as the tag filter below: empty selection shows everything,
// selecting one or more types shows only items of those types. Session-only, resets on reload.
const selectedTypes = ref<('task' | 'event')[]>([]);
const toggleTypeFilter = (type: 'task' | 'event') => {
    const current = selectedTypes.value;
    const idx = current.indexOf(type);
    if (idx === -1) {
        selectedTypes.value = [...current, type];
    } else {
        selectedTypes.value = current.filter((t) => t !== type);
    }
};
const matchesTypeFilter = (item: CalendarItem): boolean => {
    if (selectedTypes.value.length === 0) return true;
    return selectedTypes.value.includes(item.is_task ? 'task' : 'event');
};

// Every distinct tag currently on an event in view, deduped by id — mirrors
// KanbanBoard.vue's own availableTags/tag-filter convention (multi-select, OR semantics,
// empty selection shows everything).
const availableTags = computed<CategoryDef[]>(() => {
    const byId = new Map<string, CategoryDef>();
    props.items.forEach((item) => {
        (item.categories ?? []).forEach((category) => {
            if (!byId.has(category.id)) byId.set(category.id, category);
        });
    });
    return Array.from(byId.values()).sort((a, b) =>
        a.name.localeCompare(b.name),
    );
});

const selectedTagIds = ref<string[]>([]);
const toggleTagFilter = (value: string) => {
    const current = selectedTagIds.value;
    const idx = current.indexOf(value);
    if (idx === -1) {
        selectedTagIds.value = [...current, value];
    } else {
        selectedTagIds.value = current.filter((v) => v !== value);
    }
};
const matchesTagFilter = (item: CalendarItem): boolean => {
    if (selectedTagIds.value.length === 0) return true;
    const categories = item.categories ?? [];
    if (categories.length === 0)
        return selectedTagIds.value.includes(TAG_FILTER_NONE);
    return categories.some((c) => selectedTagIds.value.includes(c.id));
};

const currentMonth = ref(new Date());

const monthLabel = computed(() =>
    currentMonth.value.toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    }),
);

// Exports mirror whatever's currently on screen — the visible month, whichever
// sub-projects are currently hidden, the active tag filter, and the Tasks/Events toggle —
// so the downloaded file matches the view.
const exportQuery = computed(() => ({
    month: `${currentMonth.value.getFullYear()}-${String(currentMonth.value.getMonth() + 1).padStart(2, '0')}`,
    hidden_subprojects: Array.from(hiddenSubprojectIds),
    tags: selectedTagIds.value,
    show_tasks: selectedTypes.value.length === 0 || selectedTypes.value.includes('task'),
    show_events: selectedTypes.value.length === 0 || selectedTypes.value.includes('event'),
}));
const exportPdfUrl = computed(() =>
    projectCalendarRoutes.exportPdf.url(
        { project: props.projectId },
        { query: exportQuery.value },
    ),
);
const exportCsvUrl = computed(() =>
    projectCalendarRoutes.exportCsv.url(
        { project: props.projectId },
        { query: exportQuery.value },
    ),
);
const exportExcelUrl = computed(() =>
    projectCalendarRoutes.exportExcel.url(
        { project: props.projectId },
        { query: exportQuery.value },
    ),
);

const goToPrevMonth = () => {
    currentMonth.value = new Date(
        currentMonth.value.getFullYear(),
        currentMonth.value.getMonth() - 1,
        1,
    );
};
const goToNextMonth = () => {
    currentMonth.value = new Date(
        currentMonth.value.getFullYear(),
        currentMonth.value.getMonth() + 1,
        1,
    );
};
const goToToday = () => {
    currentMonth.value = new Date();
};

const todayKey = formatDateKey(new Date());

const gridDays = computed<GridDay[]>(() => {
    const year = currentMonth.value.getFullYear();
    const month = currentMonth.value.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    const startOffset = firstOfMonth.getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();

    const makeDay = (date: Date, inCurrentMonth: boolean): GridDay => {
        const dateKey = formatDateKey(date);
        return {
            date,
            dateKey,
            inCurrentMonth,
            isToday: dateKey === todayKey,
        };
    };

    const days: GridDay[] = [];

    for (let i = startOffset - 1; i >= 0; i--) {
        days.push(
            makeDay(new Date(year, month - 1, daysInPrevMonth - i), false),
        );
    }
    for (let d = 1; d <= daysInMonth; d++) {
        days.push(makeDay(new Date(year, month, d), true));
    }
    while (days.length % 7 !== 0) {
        const last = days[days.length - 1].date;
        days.push(
            makeDay(
                new Date(
                    last.getFullYear(),
                    last.getMonth(),
                    last.getDate() + 1,
                ),
                false,
            ),
        );
    }

    return days;
});

// Every visible item's [start, end] date-key range, one entry per item regardless of how
// many days it spans or how many weeks it crosses. Tasks have no start_at (the calendar only
// shows the single day they're due), so they always collapse to a single day, same as an Event
// with a missing start_at; a start_at after the effective due date (bad data) is clamped the
// same way rather than rendering a bar that runs backwards.
const eventRanges = computed<EventRange[]>(() => {
    const ranges: EventRange[] = [];

    for (const item of props.items) {
        if (item.is_subproject && hiddenSubprojectIds.has(item.project_id)) {
            continue;
        }
        if (!matchesTagFilter(item)) {
            continue;
        }
        if (!matchesTypeFilter(item)) {
            continue;
        }
        const dueAt = effectiveDueAt(item);
        if (!dueAt) {
            continue;
        }

        const endKey = dueAt.slice(0, 10);
        const startKey = item.start_at ? item.start_at.slice(0, 10) : endKey;
        ranges.push({
            item,
            startKey: startKey <= endKey ? startKey : endKey,
            endKey,
        });
    }

    return ranges;
});

// One lane per event, assigned once via greedy interval packing (sorted by start date, ties
// broken by longer events first) across every event touching the visible grid — not
// per-week-independently — so a bar sits at the same vertical row on every day and every week
// it spans, the way Google/Outlook's month view does.
const eventLanes = computed<Map<string, number>>(() => {
    const days = gridDays.value;
    if (days.length === 0) return new Map();

    const gridStartKey = days[0].dateKey;
    const gridEndKey = days[days.length - 1].dateKey;

    const relevant = eventRanges.value
        .filter((r) => r.endKey >= gridStartKey && r.startKey <= gridEndKey)
        .sort(
            (a, b) =>
                a.startKey.localeCompare(b.startKey) ||
                b.endKey.localeCompare(a.endKey),
        );

    const laneEndKeys: string[] = [];
    const lanes = new Map<string, number>();

    for (const range of relevant) {
        let lane = laneEndKeys.findIndex((endKey) => endKey < range.startKey);
        if (lane === -1) lane = laneEndKeys.length;
        laneEndKeys[lane] = range.endKey;
        lanes.set(range.item.id, lane);
    }

    return lanes;
});

const weeks = computed<WeekRow[]>(() => {
    const days = gridDays.value;
    const rows: WeekRow[] = [];

    for (let i = 0; i < days.length; i += 7) {
        const weekDays = days.slice(i, i + 7);
        const weekStartKey = weekDays[0].dateKey;
        const weekEndKey = weekDays[6].dateKey;

        const bars: EventBar[] = [];
        for (const range of eventRanges.value) {
            if (range.endKey < weekStartKey || range.startKey > weekEndKey) {
                continue;
            }
            const lane = eventLanes.value.get(range.item.id);
            if (lane === undefined) continue;

            const segStartKey =
                range.startKey > weekStartKey ? range.startKey : weekStartKey;
            const segEndKey =
                range.endKey < weekEndKey ? range.endKey : weekEndKey;
            const startCol = weekDays.findIndex(
                (d) => d.dateKey === segStartKey,
            );
            const endCol = weekDays.findIndex((d) => d.dateKey === segEndKey);

            bars.push({
                item: range.item,
                lane,
                startCol,
                span: endCol - startCol + 1,
                continuesBefore: range.startKey < weekStartKey,
                continuesAfter: range.endKey > weekEndKey,
            });
        }

        const laneCount =
            bars.length === 0 ? 0 : Math.max(...bars.map((b) => b.lane)) + 1;

        rows.push({ days: weekDays, bars, laneCount });
    }

    return rows;
});

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// A tagged item's bar is tinted with a light wash of its first tag's color (matching the tag
// dot right next to it) rather than the generic subproject/primary tint. Events carry at most
// one tag (see the categories docblock on CalendarItem), so there's nothing to pick between for
// them; a task with several tags just takes the first, same ordering as the dot beside it.
const barClasses = (bar: EventBar): string => {
    const tagColor = bar.item.categories?.[0]?.color;
    if (tagColor) return kanbanCardBg[tagColor] ?? kanbanCardBg.slate;

    if (bar.item.is_subproject) {
        return kanbanCardBg[
            subprojectColors.value[bar.item.project_id] ?? 'slate'
        ];
    }
    return 'bg-projector-primary-50 dark:bg-projector-primary-950/30';
};

// Carries the current URL (including its ?tab=calendar) as `from`, so the document's
// "back" button returns here instead of falling back to whatever tab was last cached.
const openItem = (item: CalendarItem) => {
    const url = projectDocumentsRoutes.show(
        { project: item.project_id, document: item.id },
        { query: { from: window.location.href } },
    ).url;
    router.visit(url);
};

// Tasks have no start_at, so they always fall into the single-"Date" case below, using their
// effective due date (see effectiveDueAt). Events default start to end for a single-date source
// row (the "Notes to Events" transformation and the list importer both do this), so they also
// commonly collapse to one plain "Date" row — only a genuine multi-day Event gets distinct
// Start/End rows.
const dateFields = (item: CalendarItem): { label: string; value: string }[] => {
    const start = item.start_at;
    const due = effectiveDueAt(item);

    if (!start || !due || start.slice(0, 10) === due.slice(0, 10)) {
        return [
            {
                label: 'Date',
                value: formatDateOnly(due ?? start, { weekday: true }),
            },
        ];
    }

    return [
        {
            label: 'Start Date',
            value: formatDateOnly(start, { weekday: true }),
        },
        { label: 'End Date', value: formatDateOnly(due, { weekday: true }) },
    ];
};

// Strips the document's rich-text HTML down to plain text for a short preview —
// off-DOM only (never inserted into the page), so this is safe for untrusted content.
const previewText = (html: string | null): string => {
    if (!html) return 'No description provided.';
    const div = document.createElement('div');
    div.innerHTML = html;
    const text = (div.textContent || '').trim().replace(/\s+/g, ' ');
    return text || 'No description provided.';
};

// Hover-card behavior on top of the (click-triggered by default) Popover primitive: open
// on mouseenter, close on mouseleave, with a short grace period so moving the cursor from
// the trigger into the popover's own content (to click "View Details") doesn't close it.
// Keyed by bar (item id + week index), not just item id — a multi-week event renders one bar
// per week it crosses, and each needs its own popover open state so hovering one segment
// doesn't also pop open its other segment sitting in a different week row.
const activeBarKey = ref<string | null>(null);
let closeTimeout: ReturnType<typeof setTimeout> | null = null;

const barKey = (bar: EventBar, weekIndex: number): string =>
    `${bar.item.id}-w${weekIndex}`;

const openBarCard = (bar: EventBar, weekIndex: number) => {
    if (closeTimeout) {
        clearTimeout(closeTimeout);
        closeTimeout = null;
    }
    activeBarKey.value = barKey(bar, weekIndex);
};

const scheduleCloseBarCard = () => {
    closeTimeout = setTimeout(() => {
        activeBarKey.value = null;
    }, 150);
};

const cancelCloseBarCard = () => {
    if (closeTimeout) {
        clearTimeout(closeTimeout);
        closeTimeout = null;
    }
};
</script>

<template>
    <div class="space-y-4">
        <div
            v-if="items.length === 0"
            class="flex flex-col items-center justify-center rounded-[2rem] border border-dashed border-gray-200 bg-gray-50/50 py-20"
        >
            <div class="mb-4 rounded-2xl bg-white p-4 shadow-sm">
                <CalendarDays class="h-8 w-8 text-gray-300" />
            </div>
            <p class="font-bold text-gray-900">No tasks or events yet</p>
            <p class="text-sm text-gray-500">
                Tasks and events with due dates, including sub-project items,
                will show up here.
            </p>
            <Button
                v-if="canManageImports"
                size="sm"
                class="mt-4 h-8 px-3 text-[10px] font-black tracking-widest uppercase"
                @click="emit('import-events')"
            >
                <Upload class="h-3.5 w-3.5" />
                Import Events
            </Button>
        </div>

        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-lg font-black text-gray-900 dark:text-white">
                    {{ monthLabel }}
                </h3>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="canManageImports"
                        size="sm"
                        class="h-8 px-3 text-[10px] font-black tracking-widest uppercase"
                        @click="emit('import-events')"
                    >
                        <Upload class="h-3.5 w-3.5" />
                        Import Events
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        size="sm"
                        class="h-8 px-3 text-[10px] font-black tracking-widest uppercase"
                    >
                        <a :href="exportPdfUrl">
                            <FileDown class="h-3.5 w-3.5" />
                            PDF
                        </a>
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        size="sm"
                        class="h-8 px-3 text-[10px] font-black tracking-widest uppercase"
                    >
                        <a :href="exportCsvUrl">
                            <FileSpreadsheet class="h-3.5 w-3.5" />
                            CSV
                        </a>
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        size="sm"
                        class="h-8 px-3 text-[10px] font-black tracking-widest uppercase"
                    >
                        <a :href="exportExcelUrl">
                            <Sheet class="h-3.5 w-3.5" />
                            Excel
                        </a>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-8 px-3 text-[10px] font-black tracking-widest uppercase"
                        @click="goToToday"
                    >
                        Today
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        class="h-8 w-8"
                        @click="goToPrevMonth"
                    >
                        <ChevronLeft class="h-4 w-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        class="h-8 w-8"
                        @click="goToNextMonth"
                    >
                        <ChevronRight class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    @click="toggleTypeFilter('task')"
                    :class="[
                        'rounded-full border px-2.5 py-1 text-[9px] font-black tracking-widest uppercase transition-all',
                        selectedTypes.includes('task')
                            ? 'border-gray-300 bg-gray-100 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'
                            : 'border-gray-200 bg-white text-gray-400 hover:border-gray-300 dark:border-gray-800 dark:bg-transparent',
                    ]"
                >
                    Tasks
                </button>
                <button
                    type="button"
                    @click="toggleTypeFilter('event')"
                    :class="[
                        'rounded-full border px-2.5 py-1 text-[9px] font-black tracking-widest uppercase transition-all',
                        selectedTypes.includes('event')
                            ? 'border-gray-300 bg-gray-100 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'
                            : 'border-gray-200 bg-white text-gray-400 hover:border-gray-300 dark:border-gray-800 dark:bg-transparent',
                    ]"
                >
                    Events
                </button>
            </div>

            <div
                v-if="subprojects.length"
                class="flex flex-wrap items-center gap-2"
            >
                <button
                    v-for="sp in subprojects"
                    :key="sp.id"
                    type="button"
                    @click="toggleSubproject(sp.id)"
                    :class="[
                        'flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[9px] font-black tracking-widest uppercase transition-all',
                        hiddenSubprojectIds.has(sp.id)
                            ? 'border-gray-200 bg-white text-gray-300 line-through dark:border-gray-800 dark:bg-transparent'
                            : 'border-transparent ' +
                              kanbanClasses[subprojectColors[sp.id] ?? 'slate'],
                    ]"
                >
                    <span
                        :class="[
                            'h-1.5 w-1.5 shrink-0 rounded-full',
                            kanbanDotClasses[
                                subprojectColors[sp.id] ?? 'slate'
                            ],
                        ]"
                    ></span>
                    {{ sp.name }}
                </button>
            </div>

            <div
                v-if="availableTags.length"
                class="flex flex-wrap items-center gap-2"
            >
                <button
                    type="button"
                    @click="selectedTagIds = []"
                    :class="[
                        'rounded-full border px-2.5 py-1 text-[9px] font-black tracking-widest uppercase transition-all',
                        selectedTagIds.length === 0
                            ? 'border-gray-300 bg-gray-100 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'
                            : 'border-gray-200 bg-white text-gray-400 hover:border-gray-300 dark:border-gray-800 dark:bg-transparent',
                    ]"
                >
                    All
                </button>
                <button
                    type="button"
                    @click="toggleTagFilter(TAG_FILTER_NONE)"
                    :class="[
                        'rounded-full border px-2.5 py-1 text-[9px] font-black tracking-widest uppercase transition-all',
                        selectedTagIds.includes(TAG_FILTER_NONE)
                            ? 'border-gray-300 bg-gray-100 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'
                            : 'border-gray-200 bg-white text-gray-400 hover:border-gray-300 dark:border-gray-800 dark:bg-transparent',
                    ]"
                >
                    None
                </button>
                <button
                    v-for="tag in availableTags"
                    :key="tag.id"
                    type="button"
                    @click="toggleTagFilter(tag.id)"
                    :class="[
                        'flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[9px] font-black tracking-widest uppercase transition-all',
                        selectedTagIds.includes(tag.id)
                            ? 'border-gray-300 bg-gray-100 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'
                            : 'border-gray-200 bg-white text-gray-400 dark:border-gray-800 dark:bg-transparent',
                    ]"
                >
                    <span
                        :class="[
                            'h-1.5 w-1.5 shrink-0 rounded-full',
                            kanbanDotClasses[tag.color],
                        ]"
                    ></span>
                    {{ tag.name }}
                </button>
            </div>

            <div
                class="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-800"
            >
                <div
                    class="grid grid-cols-7 border-b border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900"
                >
                    <div
                        v-for="label in weekdayLabels"
                        :key="label"
                        class="px-2 py-2 text-center text-[9px] font-black tracking-widest text-gray-400 uppercase"
                    >
                        {{ label }}
                    </div>
                </div>

                <div
                    v-for="(week, wi) in weeks"
                    :key="wi"
                    class="grid border-b border-gray-100 last:border-b-0 dark:border-gray-800"
                    :style="{
                        gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
                        gridTemplateRows: `26px repeat(${week.laneCount}, 22px) 6px`,
                    }"
                >
                    <div
                        v-for="(cell, ci) in week.days"
                        :key="cell.dateKey"
                        :style="{ gridColumn: ci + 1, gridRow: '1 / -1' }"
                        :class="[
                            'border-r border-gray-100 pt-1 pl-1.5 dark:border-gray-800',
                            ci === 6 ? 'border-r-0' : '',
                            cell.inCurrentMonth
                                ? 'bg-white dark:bg-transparent'
                                : 'bg-gray-50/50 dark:bg-gray-900/30',
                        ]"
                    >
                        <span
                            :class="[
                                'inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold',
                                cell.isToday
                                    ? 'bg-projector-primary-600 text-white'
                                    : cell.inCurrentMonth
                                      ? 'text-gray-700 dark:text-gray-300'
                                      : 'text-gray-300 dark:text-gray-700',
                            ]"
                        >
                            {{ cell.date.getDate() }}
                        </span>
                    </div>

                    <Popover
                        v-for="bar in week.bars"
                        :key="barKey(bar, wi)"
                        :open="activeBarKey === barKey(bar, wi)"
                    >
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                @click="openItem(bar.item)"
                                @mouseenter="openBarCard(bar, wi)"
                                @mouseleave="scheduleCloseBarCard"
                                @focus="openBarCard(bar, wi)"
                                @blur="scheduleCloseBarCard"
                                :style="{
                                    gridColumn: `${bar.startCol + 1} / span ${bar.span}`,
                                    gridRow: bar.lane + 2,
                                }"
                                :class="[
                                    'z-10 mx-0.5 flex items-center gap-1 self-center overflow-hidden px-1.5 py-0.5 text-left transition-colors hover:brightness-95',
                                    barClasses(bar),
                                    bar.continuesBefore
                                        ? 'rounded-l-none'
                                        : 'rounded-l',
                                    bar.continuesAfter
                                        ? 'rounded-r-none'
                                        : 'rounded-r',
                                ]"
                            >
                                <span
                                    v-if="bar.item.categories?.[0]"
                                    :class="[
                                        'h-1.5 w-1.5 shrink-0 rounded-full',
                                        kanbanDotClasses[
                                            bar.item.categories[0].color
                                        ],
                                    ]"
                                ></span>
                                <span
                                    class="flex-1 truncate text-[10px] font-bold text-gray-700 dark:text-gray-300"
                                >
                                    {{ bar.item.name }}
                                </span>
                            </button>
                        </PopoverTrigger>

                        <PopoverContent
                            class="w-72 space-y-3 p-4"
                            @mouseenter="cancelCloseBarCard"
                            @mouseleave="scheduleCloseBarCard"
                            @open-auto-focus.prevent
                        >
                            <div class="space-y-1">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        v-if="bar.item.is_subproject"
                                        :class="[
                                            'rounded px-1.5 py-0.5 text-[8px] font-black tracking-widest uppercase',
                                            kanbanClasses[
                                                subprojectColors[
                                                    bar.item.project_id
                                                ] ?? 'slate'
                                            ],
                                        ]"
                                    >
                                        {{ bar.item.project_name }}
                                    </span>
                                    <span
                                        v-if="bar.item.categories?.[0]"
                                        :class="[
                                            'rounded px-1.5 py-0.5 text-[8px] font-black tracking-widest uppercase',
                                            kanbanClasses[
                                                bar.item.categories[0].color
                                            ],
                                        ]"
                                    >
                                        {{ bar.item.categories[0].name }}
                                    </span>
                                    <template
                                        v-for="field in dateFields(bar.item)"
                                        :key="field.label"
                                    >
                                        <span
                                            class="text-[8px] font-black tracking-widest text-gray-400 uppercase"
                                        >
                                            {{ field.label }}
                                        </span>
                                        <span
                                            class="text-[8px] font-black tracking-widest text-gray-300 uppercase"
                                            >·</span
                                        >
                                        <span
                                            class="text-[8px] font-black tracking-widest text-gray-400 uppercase"
                                        >
                                            {{ field.value }}
                                        </span>
                                    </template>
                                </div>
                                <p
                                    class="text-sm leading-snug font-bold text-gray-900 dark:text-white"
                                >
                                    {{ bar.item.name }}
                                </p>
                            </div>

                            <p
                                class="line-clamp-4 text-xs leading-relaxed text-gray-500 dark:text-gray-400"
                            >
                                {{ previewText(bar.item.content) }}
                            </p>

                            <Button
                                size="sm"
                                class="h-8 w-full text-[10px] font-black tracking-widest uppercase"
                                @click="openItem(bar.item)"
                            >
                                View Details
                                <ArrowUpRight class="ml-1 h-3.5 w-3.5" />
                            </Button>
                        </PopoverContent>
                    </Popover>
                </div>
            </div>
        </template>
    </div>
</template>
