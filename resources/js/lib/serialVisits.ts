import { reportSaveEvent, type SaveReport } from '@/lib/clientLog';
import {
    redirectIfLoggedOut,
    redirectIfSessionExpiredError,
} from '@/lib/sessionExpiry';
import { router, type VisitOptions } from '@inertiajs/vue3';
import axios, { type AxiosError } from 'axios';
import { toast } from 'vue-sonner';

type SaveMethod = 'patch' | 'put';

/**
 * A save that was cancelled before it finished is sent again this many times in total before
 * giving up — enough to ride out a background reload or two, not so many that a page that's
 * genuinely unreachable retries forever.
 */
const MAX_ATTEMPTS = 3;

let tail: Promise<void> = Promise.resolve();

/**
 * Sends a record edit (a task's status, assignee, due date, tags…) through Inertia so that it
 * can't be lost to another request.
 *
 * Inertia cancels a visit that's still in flight the moment another one starts. A person
 * editing several records in a row (or a background reload landing mid-save) therefore cancelled
 * the earlier save before it ever reached the server — and since a cancelled visit is neither a
 * success nor an error, nothing was logged and the screen kept showing the unsaved value.
 *
 * Saves sent through here go one at a time, in the order they were made, so one can't interrupt
 * another. A save that some other visit still manages to cancel is sent again.
 */
export function saveVisit(
    method: SaveMethod,
    url: string,
    data: Record<string, unknown>,
    options: VisitOptions = {},
): void {
    tail = tail.then(() => send(method, url, data, options, 1));
}

const send = (
    method: SaveMethod,
    url: string,
    data: Record<string, unknown>,
    options: VisitOptions,
    attempt: number,
): Promise<void> =>
    new Promise((resolve) => {
        router[method](url, data as never, {
            ...options,
            onFinish: (visit) => {
                if (!visit.completed) {
                    reportSaveEvent({
                        event:
                            attempt < MAX_ATTEMPTS
                                ? 'visit-cancelled-retrying'
                                : 'visit-cancelled-gave-up',
                        method,
                        url,
                        data,
                        attempt,
                    });
                }

                if (!visit.completed && attempt < MAX_ATTEMPTS) {
                    resolve(send(method, url, data, options, attempt + 1));

                    return;
                }

                options.onFinish?.(visit);
                resolve();
            },
        });
    });

/**
 * How long saves have to stay quiet before the page's data is refreshed from the server — long
 * enough that a burst of edits (a few fields on a few records) triggers one refresh, not one each.
 */
const REFRESH_DELAY_MS = 300;

/**
 * A save that takes longer than this, from the moment it was requested, is reported even though
 * it worked.
 */
const SLOW_SAVE_MS = 3000;

/**
 * The most recent save to each URL (one per record), so a second save to the same record waits
 * for the first.
 */
const chains = new Map<string, Promise<void>>();

/**
 * Saves still in flight, and the one refresh that follows once they've all landed.
 */
let saving = 0;
let needsRefresh = false;
let refreshTimer: ReturnType<typeof setTimeout> | null = null;
let cancelRefresh: (() => void) | null = null;

const beginSave = (refresh: boolean): void => {
    saving++;
    needsRefresh = needsRefresh || refresh;

    if (refreshTimer) {
        clearTimeout(refreshTimer);
        refreshTimer = null;
    }

    // A refresh already on its way was requested before this edit reached the server, so its
    // answer would put the old value back on screen over the edit just made. Drop it; another
    // one is scheduled when this save (and any others) finish.
    if (cancelRefresh) {
        cancelRefresh();
        cancelRefresh = null;
        needsRefresh = true;
    }
};

const endSave = (): void => {
    saving--;

    if (saving === 0 && needsRefresh) {
        refreshTimer = setTimeout(refreshPage, REFRESH_DELAY_MS);
    }
};

const refreshPage = (): void => {
    refreshTimer = null;
    needsRefresh = false;

    router.reload({
        preserveScroll: true,
        preserveState: true,
        onCancelToken: (token) => {
            cancelRefresh = () => token.cancel();
        },
        onFinish: () => {
            cancelRefresh = null;
        },
    });
};

const errorMessage = (error: unknown): string => {
    const data = (
        error as AxiosError<{
            message?: string;
            errors?: Record<string, string[]>;
        }>
    ).response?.data;

    return (
        Object.values(data?.errors ?? {})[0]?.[0] ??
        data?.message ??
        'Could not save this change.'
    );
};

type SaveRecordOptions = {
    /**
     * Shown as a success toast; defaults to the confirmation the server sent back.
     */
    successMessage?: string;
    onSuccess?: () => void;
    onError?: (message: string) => void;
    /**
     * Whether to re-fetch the page's data once saves settle (default true). Pass false where the
     * caller keeps its own copy of the record up to date and nothing else on the page reads it.
     */
    refresh?: boolean;
};

/**
 * Saves a record edit (a task's status, assignee, due date, tags…) as a small JSON request
 * instead of an Inertia visit.
 *
 * An Inertia save doesn't end when the save does — it ends after the browser has also fetched and
 * redrawn the whole page the server redirects back to, which took ~4 seconds of the ~0.13 the
 * save itself needs, and meant edits made in quick succession had to wait on each other (or, before
 * saveVisit() queued them, cancelled each other). A JSON request finishes as soon as the record is
 * saved and can't be cancelled by an Inertia visit, so edits go through immediately; the page's
 * data is then refreshed once in the background when the burst of saves is over.
 *
 * Two saves to the same record still go in the order they were made.
 */
export function saveRecord(
    method: SaveMethod,
    url: string,
    data: Record<string, unknown>,
    options: SaveRecordOptions = {},
): void {
    beginSave(options.refresh ?? true);

    // Generated once per save and sent with it, so this save's line in the server's log, the
    // response it sent, and anything reported from here can be matched up.
    const saveId = newSaveId();
    const queuedSaves = saving;
    const queuedAt = performance.now();

    const run = (chains.get(url) ?? Promise.resolve()).then(async () => {
        const sentAt = performance.now();
        const report = (event: string, extra: Partial<SaveReport> = {}) =>
            reportSaveEvent({
                event,
                save_id: saveId,
                method,
                url,
                data,
                queued_saves: queuedSaves,
                duration_ms: Math.round(performance.now() - sentAt),
                ...extra,
            });

        try {
            const response = await axios({
                method,
                url,
                data,
                headers: {
                    'X-Save-Id': saveId,
                    'X-Client-Sent-At': new Date().toISOString(),
                    'X-Client-Queued-Saves': String(queuedSaves),
                },
            });

            if (redirectIfLoggedOut(response)) {
                report('session-expired-redirected-to-login', {
                    status: response.status,
                });

                return;
            }

            // Slow but successful saves are worth knowing about: they're what makes a person
            // edit again before the first one has landed.
            if (performance.now() - queuedAt > SLOW_SAVE_MS) {
                report('slow-save', {
                    status: response.status,
                    duration_ms: Math.round(performance.now() - queuedAt),
                });
            }

            const message =
                options.successMessage ??
                (typeof response.data?.message === 'string'
                    ? response.data.message
                    : undefined);

            if (message) {
                toast.success(message);
            }

            options.onSuccess?.();
        } catch (error) {
            const failure = error as AxiosError;

            report('save-failed', {
                status: failure.response?.status ?? null,
                code: failure.code ?? null,
                message: errorMessage(error),
            });

            if (redirectIfSessionExpiredError(error)) {
                return;
            }

            // Whatever the screen assumed about this edit is now unproven, so bring it back in
            // line with what the server actually has.
            needsRefresh = true;
            options.onError?.(errorMessage(error));
        } finally {
            endSave();
        }
    });

    chains.set(url, run);
    void run.then(() => {
        if (chains.get(url) === run) {
            chains.delete(url);
        }
    });
}

const newSaveId = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
