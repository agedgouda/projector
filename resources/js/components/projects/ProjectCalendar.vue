<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    KANBAN_COLOR_PALETTE,
    kanbanClasses,
    kanbanDotClasses,
    priorityDotClasses,
} from '@/lib/constants';
import { kanbanCardBg } from '@/lib/kanban-theme';
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
} from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    projectId: string;
    items: CalendarItem[];
}>();

const page = usePage();
const usesExternalDueDates = computed(
    () => (page.props as any).orgMembership?.uses_external_due_dates ?? false,
);

interface Marker {
    item: CalendarItem;
    field: 'due_at' | 'external_due_at';
}

interface DayCell {
    date: Date;
    dateKey: string;
    inCurrentMonth: boolean;
    isToday: boolean;
    markers: Marker[];
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

const currentMonth = ref(new Date());

const monthLabel = computed(() =>
    currentMonth.value.toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    }),
);

// Exports mirror whatever's currently on screen — the visible month, and whichever
// sub-projects are currently hidden — so the downloaded file matches the view.
const exportQuery = computed(() => ({
    month: `${currentMonth.value.getFullYear()}-${String(currentMonth.value.getMonth() + 1).padStart(2, '0')}`,
    hidden_subprojects: Array.from(hiddenSubprojectIds),
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

const markersByDate = computed(() => {
    const map: Record<string, Marker[]> = {};
    const push = (dateKey: string, marker: Marker) => {
        (map[dateKey] ??= []).push(marker);
    };

    for (const item of props.items) {
        if (item.is_subproject && hiddenSubprojectIds.has(item.project_id)) {
            continue;
        }
        if (item.due_at) {
            push(item.due_at.slice(0, 10), { item, field: 'due_at' });
        }
        if (item.external_due_at && usesExternalDueDates.value) {
            push(item.external_due_at.slice(0, 10), {
                item,
                field: 'external_due_at',
            });
        }
    }

    return map;
});

const todayKey = formatDateKey(new Date());

const weeks = computed<DayCell[][]>(() => {
    const year = currentMonth.value.getFullYear();
    const month = currentMonth.value.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    const startOffset = firstOfMonth.getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();

    const makeCell = (date: Date, inCurrentMonth: boolean): DayCell => {
        const dateKey = formatDateKey(date);
        return {
            date,
            dateKey,
            inCurrentMonth,
            isToday: dateKey === todayKey,
            markers: markersByDate.value[dateKey] ?? [],
        };
    };

    const cells: DayCell[] = [];

    for (let i = startOffset - 1; i >= 0; i--) {
        cells.push(
            makeCell(new Date(year, month - 1, daysInPrevMonth - i), false),
        );
    }
    for (let d = 1; d <= daysInMonth; d++) {
        cells.push(makeCell(new Date(year, month, d), true));
    }
    while (cells.length % 7 !== 0) {
        const last = cells[cells.length - 1].date;
        cells.push(
            makeCell(
                new Date(
                    last.getFullYear(),
                    last.getMonth(),
                    last.getDate() + 1,
                ),
                false,
            ),
        );
    }

    const result: DayCell[][] = [];
    for (let i = 0; i < cells.length; i += 7) {
        result.push(cells.slice(i, i + 7));
    }
    return result;
});

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const markerClasses = (marker: Marker): string => {
    if (marker.item.is_subproject) {
        return kanbanCardBg[
            subprojectColors.value[marker.item.project_id] ?? 'slate'
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

const formatMarkerDate = (marker: Marker): string => {
    const raw =
        marker.field === 'external_due_at'
            ? marker.item.external_due_at
            : marker.item.due_at;
    if (!raw) return '';
    return new Date(raw).toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
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
const activeMarkerKey = ref<string | null>(null);
let closeTimeout: ReturnType<typeof setTimeout> | null = null;

const markerKey = (marker: Marker): string =>
    `${marker.item.id}-${marker.field}`;

const openMarkerCard = (marker: Marker) => {
    if (closeTimeout) {
        clearTimeout(closeTimeout);
        closeTimeout = null;
    }
    activeMarkerKey.value = markerKey(marker);
};

const scheduleCloseMarkerCard = () => {
    closeTimeout = setTimeout(() => {
        activeMarkerKey.value = null;
    }, 150);
};

const cancelCloseMarkerCard = () => {
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
            <p class="font-bold text-gray-900">No items with due dates yet</p>
            <p class="text-sm text-gray-500">
                Due dates set on tasks and sub-project tasks will show up here.
            </p>
        </div>

        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-lg font-black text-gray-900 dark:text-white">
                    {{ monthLabel }}
                </h3>
                <div class="flex items-center gap-2">
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
                    class="grid grid-cols-7 border-b border-gray-100 last:border-b-0 dark:border-gray-800"
                >
                    <div
                        v-for="cell in week"
                        :key="cell.dateKey"
                        :class="[
                            'min-h-[110px] border-r border-gray-100 p-1.5 align-top last:border-r-0 dark:border-gray-800',
                            cell.inCurrentMonth
                                ? 'bg-white dark:bg-transparent'
                                : 'bg-gray-50/50 dark:bg-gray-900/30',
                        ]"
                    >
                        <span
                            :class="[
                                'mb-1 inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold',
                                cell.isToday
                                    ? 'bg-projector-primary-600 text-white'
                                    : cell.inCurrentMonth
                                      ? 'text-gray-700 dark:text-gray-300'
                                      : 'text-gray-300 dark:text-gray-700',
                            ]"
                        >
                            {{ cell.date.getDate() }}
                        </span>

                        <div class="space-y-1">
                            <Popover
                                v-for="(marker, mi) in cell.markers"
                                :key="`${marker.item.id}-${marker.field}-${mi}`"
                                :open="activeMarkerKey === markerKey(marker)"
                            >
                                <PopoverTrigger as-child>
                                    <button
                                        type="button"
                                        @click="openItem(marker.item)"
                                        @mouseenter="openMarkerCard(marker)"
                                        @mouseleave="scheduleCloseMarkerCard"
                                        @focus="openMarkerCard(marker)"
                                        @blur="scheduleCloseMarkerCard"
                                        :class="[
                                            'flex w-full flex-col items-start gap-0.5 rounded px-1.5 py-1 text-left transition-colors hover:brightness-95',
                                            markerClasses(marker),
                                        ]"
                                    >
                                        <span
                                            class="flex w-full items-center gap-1"
                                        >
                                            <span
                                                :class="[
                                                    'h-1.5 w-1.5 shrink-0 rounded-full',
                                                    priorityDotClasses[
                                                        marker.item.priority ??
                                                            'low'
                                                    ],
                                                ]"
                                            ></span>
                                            <span
                                                class="flex-1 truncate text-[10px] font-bold text-gray-700 dark:text-gray-300"
                                            >
                                                {{ marker.item.name }}
                                            </span>
                                            <span
                                                v-if="
                                                    marker.field ===
                                                    'external_due_at'
                                                "
                                                class="shrink-0 text-[7px] font-black tracking-widest text-gray-400 uppercase"
                                            >
                                                Ext
                                            </span>
                                        </span>
                                        <span
                                            v-if="marker.item.is_subproject"
                                            class="w-full truncate pl-2.5 text-[8px] font-black tracking-widest text-gray-400 uppercase"
                                        >
                                            {{ marker.item.project_name }}
                                        </span>
                                    </button>
                                </PopoverTrigger>

                                <PopoverContent
                                    class="w-72 space-y-3 p-4"
                                    @mouseenter="cancelCloseMarkerCard"
                                    @mouseleave="scheduleCloseMarkerCard"
                                    @open-auto-focus.prevent
                                >
                                    <div class="space-y-1">
                                        <div
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <span
                                                v-if="marker.item.is_subproject"
                                                :class="[
                                                    'rounded px-1.5 py-0.5 text-[8px] font-black tracking-widest uppercase',
                                                    kanbanClasses[
                                                        subprojectColors[
                                                            marker.item
                                                                .project_id
                                                        ] ?? 'slate'
                                                    ],
                                                ]"
                                            >
                                                {{ marker.item.project_name }}
                                            </span>
                                            <span
                                                v-if="
                                                    marker.field ===
                                                    'external_due_at'
                                                "
                                                class="text-[8px] font-black tracking-widest text-gray-400 uppercase"
                                            >
                                                External Due Date
                                            </span>
                                            <span
                                                v-else
                                                class="text-[8px] font-black tracking-widest text-gray-400 uppercase"
                                            >
                                                {{
                                                    usesExternalDueDates
                                                        ? 'Internal Due Date'
                                                        : 'Due Date'
                                                }}
                                            </span>
                                            <span
                                                class="text-[8px] font-black tracking-widest text-gray-300 uppercase"
                                                >·</span
                                            >
                                            <span
                                                class="text-[8px] font-black tracking-widest text-gray-400 uppercase"
                                            >
                                                {{ formatMarkerDate(marker) }}
                                            </span>
                                        </div>
                                        <p
                                            class="text-sm leading-snug font-bold text-gray-900 dark:text-white"
                                        >
                                            {{ marker.item.name }}
                                        </p>
                                    </div>

                                    <p
                                        class="line-clamp-4 text-xs leading-relaxed text-gray-500 dark:text-gray-400"
                                    >
                                        {{ previewText(marker.item.content) }}
                                    </p>

                                    <Button
                                        size="sm"
                                        class="h-8 w-full text-[10px] font-black tracking-widest uppercase"
                                        @click="openItem(marker.item)"
                                    >
                                        View Details
                                        <ArrowUpRight
                                            class="ml-1 h-3.5 w-3.5"
                                        />
                                    </Button>
                                </PopoverContent>
                            </Popover>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="usesExternalDueDates"
                class="flex items-center gap-1.5 text-[10px] font-bold tracking-widest text-gray-400 uppercase"
            >
                <span
                    class="rounded border border-gray-200 px-1 text-[7px] font-black tracking-widest text-gray-400 uppercase"
                    >Ext</span
                >
                marks an external due date
            </div>
        </template>
    </div>
</template>
