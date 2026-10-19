<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import DateField from '@/components/DateField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { extract } from '@/routes/demo';
import { Head } from '@inertiajs/vue3';
import { CalendarDays, Check, Sparkles, Undo2, User, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type DemoTask = {
    title: string;
    description: string;
    criteria: string[];
    priority: 'low' | 'medium' | 'high';
    due_date: string | null;
    assignee: string | null;
    tags: string[];
};

type DemoSample = {
    id: string;
    title: string;
    meeting_date: string;
    team: string[];
    notes: string;
    tasks: DemoTask[] | null;
};

type TaskState = 'draft' | 'approved' | 'discarded';

const props = defineProps<{
    samples: DemoSample[];
    tags: string[];
    minNotesLength: number;
    maxNotesLength: number;
    turnstileSiteKey?: string | null;
    parentOrigin?: string | null;
}>();

const CUSTOM = 'custom';

const selectedSampleId = ref<string>(props.samples[0]?.id ?? CUSTOM);
const notes = ref('');
const meetingDate = ref('');
const teamText = ref('');

const tasks = ref<DemoTask[]>([]);
const taskStates = ref<TaskState[]>([]);
const resultSource = ref<'sample' | 'live' | null>(null);
const processing = ref(false);
const errorMessage = ref<string | null>(null);

const selectedSample = computed(
    () =>
        props.samples.find((sample) => sample.id === selectedSampleId.value) ??
        null,
);

const team = computed(() =>
    teamText.value
        .split(',')
        .map((name) => name.trim())
        .filter((name) => name !== ''),
);

const isUneditedSample = computed(() => {
    const sample = selectedSample.value;

    return (
        sample !== null &&
        sample.tasks !== null &&
        notes.value === sample.notes &&
        meetingDate.value === sample.meeting_date &&
        team.value.join('|') === sample.team.join('|')
    );
});

const notesTooShort = computed(
    () => notes.value.trim().length < props.minNotesLength,
);
const notesTooLong = computed(() => notes.value.length > props.maxNotesLength);

const needsTurnstile = computed(
    () => !!props.turnstileSiteKey && !isUneditedSample.value,
);
const turnstileToken = ref('');

const canSubmit = computed(
    () =>
        !processing.value &&
        !notesTooShort.value &&
        !notesTooLong.value &&
        meetingDate.value !== '' &&
        (!needsTurnstile.value || turnstileToken.value !== ''),
);

const draftTasks = computed(() =>
    tasks.value
        .map((task, index) => ({ task, index }))
        .filter(({ index }) => taskStates.value[index] === 'draft'),
);
const approvedTasks = computed(() =>
    tasks.value
        .map((task, index) => ({ task, index }))
        .filter(({ index }) => taskStates.value[index] === 'approved'),
);
const discardedCount = computed(
    () => taskStates.value.filter((state) => state === 'discarded').length,
);

const selectSample = (id: string): void => {
    selectedSampleId.value = id;
    errorMessage.value = null;
    clearResults();

    const sample = props.samples.find((candidate) => candidate.id === id);

    if (sample) {
        notes.value = sample.notes;
        meetingDate.value = sample.meeting_date;
        teamText.value = sample.team.join(', ');
    } else {
        notes.value = '';
        meetingDate.value = new Date().toISOString().slice(0, 10);
        teamText.value = '';
    }
};

const clearResults = (): void => {
    tasks.value = [];
    taskStates.value = [];
    resultSource.value = null;
};

const showTasks = (result: DemoTask[], source: 'sample' | 'live'): void => {
    tasks.value = result;
    taskStates.value = result.map(() => 'draft');
    resultSource.value = source;
};

const getCsrfToken = (): string => {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const run = async (): Promise<void> => {
    errorMessage.value = null;

    if (isUneditedSample.value && selectedSample.value?.tasks) {
        showTasks(selectedSample.value.tasks, 'sample');
        return;
    }

    processing.value = true;
    clearResults();

    try {
        const response = await fetch(extract().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                notes: notes.value,
                meeting_date: meetingDate.value,
                team: team.value,
                'cf-turnstile-response': turnstileToken.value,
            }),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const firstError = data.errors
                ? (Object.values(data.errors)[0] as string[] | undefined)?.[0]
                : undefined;
            errorMessage.value =
                firstError ??
                data.message ??
                'Something went wrong. Please try again.';
            return;
        }

        showTasks(Array.isArray(data.tasks) ? data.tasks : [], 'live');
    } catch {
        errorMessage.value =
            'Could not reach Projector. Check your connection and try again.';
    } finally {
        processing.value = false;
        resetTurnstile();
    }
};

const setTaskState = (index: number, state: TaskState): void => {
    taskStates.value[index] = state;
};

const restoreDiscarded = (): void => {
    taskStates.value = taskStates.value.map((state) =>
        state === 'discarded' ? 'draft' : state,
    );
};

const priorityClass = (priority: DemoTask['priority']): string =>
    ({
        high: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
        medium: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
        low: 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
    })[priority];

const formatDate = (date: string): string =>
    new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });

// --- Cloudflare Turnstile (only for live runs) ---

const TURNSTILE_SCRIPT_SRC =
    'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

type TurnstileApi = {
    render: (
        container: HTMLElement,
        options: Record<string, unknown>,
    ) => string;
    reset: (widgetId?: string) => void;
};

const turnstileContainer = ref<HTMLElement | null>(null);
let turnstileWidgetId: string | undefined;

const turnstileApi = (): TurnstileApi | undefined =>
    (window as unknown as { turnstile?: TurnstileApi }).turnstile;

const renderTurnstile = (): void => {
    const api = turnstileApi();

    if (
        !api ||
        !turnstileContainer.value ||
        turnstileContainer.value.hasChildNodes() ||
        !props.turnstileSiteKey
    ) {
        return;
    }

    turnstileWidgetId = api.render(turnstileContainer.value, {
        sitekey: props.turnstileSiteKey,
        callback: (token: string) => (turnstileToken.value = token),
        'expired-callback': () => (turnstileToken.value = ''),
        'error-callback': () => (turnstileToken.value = ''),
    });
};

// Tokens are single-use, so every live run needs a fresh one.
const resetTurnstile = (): void => {
    turnstileToken.value = '';
    turnstileApi()?.reset(turnstileWidgetId);
};

const loadTurnstile = (): void => {
    if (!props.turnstileSiteKey) {
        return;
    }

    if (turnstileApi()) {
        renderTurnstile();
        return;
    }

    if (!document.querySelector(`script[src="${TURNSTILE_SCRIPT_SRC}"]`)) {
        const script = document.createElement('script');
        script.src = TURNSTILE_SCRIPT_SRC;
        script.async = true;
        script.onload = renderTurnstile;
        document.head.appendChild(script);
    }
};

watch(needsTurnstile, (needed) => {
    if (needed) {
        requestAnimationFrame(loadTurnstile);
    }
});

// --- Iframe height, so the marketing site can size the embed to fit ---

const root = ref<HTMLElement | null>(null);
let resizeObserver: ResizeObserver | null = null;

const postHeight = (): void => {
    if (window.parent === window || !props.parentOrigin || !root.value) {
        return;
    }

    window.parent.postMessage(
        {
            type: 'projector-demo:height',
            height: Math.ceil(root.value.getBoundingClientRect().height),
        },
        props.parentOrigin,
    );
};

onMounted(() => {
    selectSample(selectedSampleId.value);

    if (root.value && typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(postHeight);
        resizeObserver.observe(root.value);
    }

    if (needsTurnstile.value) {
        loadTurnstile();
    }
});

onBeforeUnmount(() => resizeObserver?.disconnect());
</script>

<template>
    <Head title="Try Projector">
        <meta name="robots" content="noindex" />
    </Head>

    <div
        ref="root"
        class="bg-[#FDFDFC] text-slate-900 dark:bg-slate-950 dark:text-slate-100"
    >
        <div class="mx-auto flex max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <AppLogoIcon class="h-7 w-7 text-projector-primary-600" />
                    <span class="text-sm font-black tracking-tighter uppercase"
                        >Projector</span
                    >
                    <span class="text-sm text-muted-foreground"
                        >· Live demo</span
                    >
                </div>
                <a
                    href="/register"
                    target="_top"
                    class="text-[11px] font-black tracking-widest text-projector-primary-600 uppercase hover:text-projector-primary-700 dark:text-projector-primary-400"
                >
                    Start free trial
                </a>
            </header>

            <section class="flex flex-col gap-2">
                <h1
                    class="text-2xl font-black tracking-tight text-balance sm:text-3xl"
                >
                    Turn meeting notes into tasks
                </h1>
                <p class="max-w-2xl text-muted-foreground">
                    Pick a sample meeting or paste your own notes. Projector's
                    AI drafts the tasks, with owners, due dates and priorities,
                    and you decide which ones to keep. Change anything in a
                    sample and it runs live.
                </p>
            </section>

            <div class="grid gap-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
                <!-- Input -->
                <section
                    class="flex min-w-0 flex-col gap-5"
                    aria-label="Meeting notes"
                >
                    <div
                        class="flex flex-wrap gap-2"
                        role="radiogroup"
                        aria-label="Choose a meeting"
                    >
                        <button
                            v-for="sample in samples"
                            :key="sample.id"
                            type="button"
                            role="radio"
                            :aria-checked="selectedSampleId === sample.id"
                            :class="[
                                'rounded-full border px-3 py-1.5 text-sm transition-colors',
                                selectedSampleId === sample.id
                                    ? 'border-projector-primary-500 bg-projector-primary-50 font-medium text-projector-primary-700 dark:bg-projector-primary-950 dark:text-projector-primary-300'
                                    : 'border-border hover:bg-accent',
                            ]"
                            @click="selectSample(sample.id)"
                        >
                            {{ sample.title }}
                        </button>
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="selectedSampleId === CUSTOM"
                            :class="[
                                'rounded-full border px-3 py-1.5 text-sm transition-colors',
                                selectedSampleId === CUSTOM
                                    ? 'border-projector-primary-500 bg-projector-primary-50 font-medium text-projector-primary-700 dark:bg-projector-primary-950 dark:text-projector-primary-300'
                                    : 'border-border hover:bg-accent',
                            ]"
                            @click="selectSample(CUSTOM)"
                        >
                            Use my own notes
                        </button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-[auto_minmax(0,1fr)]">
                        <div class="flex flex-col gap-1.5">
                            <Label for="demo-meeting-date">Meeting date</Label>
                            <DateField
                                id="demo-meeting-date"
                                v-model="meetingDate"
                                trigger-class="w-full sm:w-44"
                            />
                        </div>
                        <div class="flex min-w-0 flex-col gap-1.5">
                            <Label for="demo-team"
                                >Team
                                <span class="font-normal text-muted-foreground"
                                    >(comma-separated)</span
                                ></Label
                            >
                            <Input
                                id="demo-team"
                                v-model="teamText"
                                placeholder="e.g. Maya Chen, Luis Ortega"
                            />
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-baseline justify-between gap-2">
                            <Label for="demo-notes">Notes or transcript</Label>
                            <span
                                :class="[
                                    'text-xs tabular-nums',
                                    notesTooLong
                                        ? 'text-destructive'
                                        : 'text-muted-foreground',
                                ]"
                            >
                                {{ notes.length.toLocaleString() }} /
                                {{ maxNotesLength.toLocaleString() }}
                            </span>
                        </div>
                        <Textarea
                            id="demo-notes"
                            v-model="notes"
                            rows="16"
                            class="font-mono text-[13px] leading-relaxed"
                            placeholder="Paste meeting notes or a transcript. Mention who is doing what and by when."
                        />
                        <p class="text-xs text-muted-foreground">
                            Please don't paste confidential information. Your
                            text is sent to our AI provider and isn't saved.
                        </p>
                    </div>

                    <div class="flex flex-col gap-3">
                        <p class="text-sm text-muted-foreground">
                            <template v-if="isUneditedSample"
                                >This sample has a saved result from a real AI
                                run. Edit anything to run it live.</template
                            >
                            <template v-else
                                >This will run live through the AI. It usually
                                takes 10–30 seconds.</template
                            >
                        </p>

                        <div v-show="needsTurnstile" ref="turnstileContainer" />

                        <div class="flex flex-wrap items-center gap-3">
                            <Button :disabled="!canSubmit" @click="run">
                                <Spinner v-if="processing" />
                                <Sparkles v-else class="size-4" />
                                {{
                                    processing
                                        ? 'Drafting tasks…'
                                        : 'Draft tasks'
                                }}
                            </Button>
                            <span
                                v-if="notesTooShort && !processing"
                                class="text-xs text-muted-foreground"
                            >
                                Add at least {{ minNotesLength }} characters of
                                notes.
                            </span>
                        </div>

                        <p
                            v-if="errorMessage"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errorMessage }}
                        </p>
                    </div>
                </section>

                <!-- Output -->
                <section
                    class="flex min-w-0 flex-col gap-4"
                    aria-label="Drafted tasks"
                    aria-live="polite"
                >
                    <div
                        v-if="!resultSource && !processing"
                        class="flex min-h-64 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border p-8 text-center"
                    >
                        <Sparkles class="size-6 text-projector-primary-500" />
                        <p class="font-medium">Drafted tasks appear here</p>
                        <p class="max-w-sm text-sm text-muted-foreground">
                            Nothing becomes a task until you approve it. That's
                            how Projector works too.
                        </p>
                    </div>

                    <div
                        v-else-if="processing"
                        class="flex flex-col gap-3"
                        aria-busy="true"
                    >
                        <div
                            v-for="n in 3"
                            :key="n"
                            class="h-28 animate-pulse rounded-lg bg-muted"
                        />
                    </div>

                    <template v-else>
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <h2 class="text-lg font-bold">
                                {{ tasks.length }} tasks drafted
                            </h2>
                            <span class="text-xs text-muted-foreground">
                                {{
                                    resultSource === 'live'
                                        ? 'Live AI run'
                                        : 'Saved result from a real AI run'
                                }}
                            </span>
                        </div>

                        <p
                            v-if="tasks.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            The AI didn't find any action items in these notes.
                        </p>

                        <div
                            class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
                        >
                            <div class="flex min-w-0 flex-col gap-3">
                                <h3
                                    class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Needs review · {{ draftTasks.length }}
                                </h3>

                                <article
                                    v-for="{ task, index } in draftTasks"
                                    :key="index"
                                    class="flex flex-col gap-2 rounded-lg border border-border bg-background p-4"
                                >
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <h4 class="leading-snug font-semibold">
                                            {{ task.title }}
                                        </h4>
                                        <Badge
                                            variant="outline"
                                            :class="[
                                                'capitalize',
                                                priorityClass(task.priority),
                                            ]"
                                            >{{ task.priority }}</Badge
                                        >
                                    </div>
                                    <p
                                        v-if="task.description"
                                        class="text-sm text-muted-foreground"
                                    >
                                        {{ task.description }}
                                    </p>
                                    <ul
                                        v-if="task.criteria.length"
                                        class="list-disc pl-5 text-sm text-muted-foreground"
                                    >
                                        <li
                                            v-for="(
                                                criterion, criterionIndex
                                            ) in task.criteria"
                                            :key="criterionIndex"
                                        >
                                            {{ criterion }}
                                        </li>
                                    </ul>
                                    <div
                                        class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1"
                                            ><User class="size-3.5" />{{
                                                task.assignee ?? 'Unassigned'
                                            }}</span
                                        >
                                        <span
                                            class="inline-flex items-center gap-1"
                                            ><CalendarDays class="size-3.5" />{{
                                                task.due_date
                                                    ? formatDate(task.due_date)
                                                    : 'No due date'
                                            }}</span
                                        >
                                        <Badge
                                            v-for="tag in task.tags"
                                            :key="tag"
                                            variant="secondary"
                                            >{{ tag }}</Badge
                                        >
                                    </div>
                                    <div class="flex gap-2 pt-1">
                                        <Button
                                            size="sm"
                                            @click="
                                                setTaskState(index, 'approved')
                                            "
                                            ><Check
                                                class="size-4"
                                            />Approve</Button
                                        >
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            @click="
                                                setTaskState(index, 'discarded')
                                            "
                                            ><X class="size-4" />Discard</Button
                                        >
                                    </div>
                                </article>

                                <p
                                    v-if="tasks.length && !draftTasks.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    All reviewed.
                                </p>
                                <button
                                    v-if="discardedCount"
                                    type="button"
                                    class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground underline-offset-2 hover:underline"
                                    @click="restoreDiscarded"
                                >
                                    <Undo2 class="size-3.5" />Restore
                                    {{ discardedCount }} discarded
                                </button>
                            </div>

                            <div
                                class="flex min-w-0 flex-col gap-3 rounded-lg bg-muted/60 p-3"
                            >
                                <h3
                                    class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    To do · {{ approvedTasks.length }}
                                </h3>
                                <p
                                    v-if="!approvedTasks.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    Approved tasks land on the board here.
                                </p>
                                <div
                                    v-for="{ task, index } in approvedTasks"
                                    :key="index"
                                    class="flex flex-col gap-1.5 rounded-md border border-border bg-background p-3"
                                >
                                    <div
                                        class="flex items-start justify-between gap-2"
                                    >
                                        <span
                                            class="text-sm leading-snug font-medium"
                                            >{{ task.title }}</span
                                        >
                                        <button
                                            type="button"
                                            class="text-muted-foreground hover:text-foreground"
                                            :aria-label="`Move ${task.title} back to review`"
                                            @click="
                                                setTaskState(index, 'draft')
                                            "
                                        >
                                            <Undo2 class="size-3.5" />
                                        </button>
                                    </div>
                                    <div
                                        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                                    >
                                        <span>{{
                                            task.assignee ?? 'Unassigned'
                                        }}</span>
                                        <span v-if="task.due_date"
                                            >Due
                                            {{
                                                formatDate(task.due_date)
                                            }}</span
                                        >
                                        <Badge
                                            variant="outline"
                                            :class="[
                                                'capitalize',
                                                priorityClass(task.priority),
                                            ]"
                                            >{{ task.priority }}</Badge
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </section>
            </div>

            <section
                class="flex flex-col items-start gap-3 rounded-lg border border-border p-6 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex flex-col gap-1">
                    <p class="font-semibold">
                        In Projector, every approved task stays linked to the
                        meeting it came from.
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Free for 6 weeks. No credit card required.
                    </p>
                </div>
                <Button as-child>
                    <a href="/register" target="_top">Start your free trial</a>
                </Button>
            </section>
        </div>
    </div>
</template>
