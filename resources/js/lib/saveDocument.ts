import { reportSaveEvent } from '@/lib/clientLog';
import {
    redirectIfLoggedOut,
    redirectIfSessionExpiredError,
} from '@/lib/sessionExpiry';
import projectDocumentsRoutes from '@/routes/projects/documents/index';
import axios, { type AxiosError } from 'axios';
import { toast } from 'vue-sonner';

type Fields = Record<string, unknown>;

type SaveDocumentOptions = {
    /**
     * The record as the screen has it now. When given, the fields just chosen are shown right away
     * and go back to these values if the save fails; when left out, nothing is shown until the
     * saved record comes back.
     */
    current?: Record<string, any>;
    /**
     * Puts values on the screen's copy of the record.
     */
    apply: (data: Record<string, any>) => void;
    /**
     * Shown as a success toast; defaults to the confirmation the server sent back.
     */
    successMessage?: string;
};

/**
 * Saves that haven't finished yet, per document. A document's saves go one at a time, in the
 * order they were made, so two quick edits can't arrive out of order.
 */
const queues = new Map<string, { tail: Promise<unknown>; pending: number }>();

/**
 * Fields that wait for the saved record instead of being shown early: an assignee is sent as an
 * id but shown as a name, tags are sent as ids but shown as tag objects, and the name and content
 * are drafts being typed into — a failed save must not swap them back under the person's hands.
 */
const SHOWN_ONLY_FROM_SAVED_RECORD = [
    'assignee_id',
    'category_ids',
    'name',
    'content',
];

const DATE_FIELDS = ['due_at', 'external_due_at', 'start_at'];

/**
 * What a picker or an emptied date input hands over, as the server takes it: "unassigned" and an
 * empty date both mean "none". An assignee is otherwise sent as it comes — a user id or
 * "inv:{id}" — since the server resolves both.
 */
const normalize = (fields: Fields): Fields =>
    Object.fromEntries(
        Object.entries(fields).map(([key, value]) => [
            key,
            (key === 'assignee_id' && value === 'unassigned') ||
            (DATE_FIELDS.includes(key) && value === '')
                ? null
                : value,
        ]),
    );

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

/**
 * Saves changes to one document — any of its fields, all through the same request — and puts
 * the record the server answers with on screen, so what's shown is what's stored. Until that
 * answer arrives, the values just chosen are shown as they are (the card doesn't wait to move).
 * If the save fails, those values go back and an error is shown.
 *
 * Returns whether the save succeeded.
 */
export async function saveDocument(
    projectId: string,
    documentId: string | number,
    rawFields: Fields,
    options: SaveDocumentOptions,
): Promise<boolean> {
    const fields = normalize(rawFields);
    const id = String(documentId);
    const url = projectDocumentsRoutes.updateAttributes.url({
        project: projectId,
        document: id,
    });

    const early = options.current
        ? Object.fromEntries(
              Object.entries(fields).filter(
                  ([key]) => !SHOWN_ONLY_FROM_SAVED_RECORD.includes(key),
              ),
          )
        : {};
    const before = Object.fromEntries(
        Object.keys(early).map((key) => [key, options.current?.[key]]),
    );

    options.apply(early);

    const queue = queues.get(id) ?? { tail: Promise.resolve(), pending: 0 };
    queue.pending++;
    queues.set(id, queue);

    const saveId = crypto.randomUUID();

    const run = queue.tail.then(async (): Promise<boolean> => {
        const startedAt = performance.now();
        const report = (event: string, extra: Record<string, unknown> = {}) =>
            reportSaveEvent({
                event,
                save_id: saveId,
                method: 'patch',
                url,
                data: fields,
                queued_saves: queue.pending,
                duration_ms: Math.round(performance.now() - startedAt),
                ...extra,
            });

        try {
            const response = await axios.patch(url, fields, {
                headers: {
                    'X-Save-Id': saveId,
                    'X-Client-Sent-At': new Date().toISOString(),
                    'X-Client-Queued-Saves': String(queue.pending),
                },
            });

            if (redirectIfLoggedOut(response)) {
                report('session-expired-redirected-to-login', {
                    status: response.status,
                });

                return false;
            }

            if (queue.pending === 1) {
                options.apply(response.data.document);
            }

            const message = options.successMessage ?? response.data.message;

            if (typeof message === 'string') {
                toast.success(message);
            }

            return true;
        } catch (error) {
            const failure = error as AxiosError;

            report('save-failed', {
                status: failure.response?.status ?? null,
                code: failure.code ?? null,
                message: errorMessage(error),
            });

            if (!redirectIfSessionExpiredError(error)) {
                options.apply(before);
                toast.error(errorMessage(error));
            }

            return false;
        } finally {
            queue.pending--;

            if (queue.pending === 0) {
                queues.delete(id);
            }
        }
    });

    queue.tail = run;

    return run;
}
