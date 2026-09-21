/**
 * Reports to the server the things about a save that only the browser can see — the request
 * never arrived (network down, the page was closed), it was cancelled, the session had expired, it
 * took too long. The server logs everything that reaches it (see App\Services\Logging\
 * RecordSaveLogger); this is the other half of the trail.
 *
 * Reports are written to localStorage *before* they're sent and removed only once the server has
 * acknowledged them — the failure being reported is often the network itself being down, which
 * would swallow a fire-and-forget request. Anything unsent is retried on the next report and on
 * the next app boot (see app.ts), the same approach the stale-asset reports use.
 */

const PENDING_KEY = 'pendingSaveReports';
const MAX_PENDING = 50;
const ENDPOINT = '/client-logs/record-save';

export interface SaveReport {
    event: string;
    save_id?: string;
    method?: string;
    url?: string;
    data?: Record<string, unknown>;
    status?: number | null;
    code?: string | null;
    message?: string;
    duration_ms?: number;
    online?: boolean;
    queued_saves?: number;
    attempt?: number;
    page_url?: string;
    client_version?: string | null;
}

const readPending = (): SaveReport[] => {
    try {
        return JSON.parse(localStorage.getItem(PENDING_KEY) ?? '[]');
    } catch {
        return [];
    }
};

const writePending = (reports: SaveReport[]): void => {
    try {
        if (reports.length === 0) {
            localStorage.removeItem(PENDING_KEY);
        } else {
            localStorage.setItem(
                PENDING_KEY,
                JSON.stringify(reports.slice(-MAX_PENDING)),
            );
        }
    } catch {
        // Private browsing / storage disabled / quota exceeded — the direct send in
        // flushSaveReports() is then this report's only chance.
    }
};

const currentInertiaVersion = (): string | null => {
    try {
        const page = document.getElementById('app')?.dataset.page;

        return page ? (JSON.parse(page).version ?? null) : null;
    } catch {
        return null;
    }
};

let flushing = false;

export async function flushSaveReports(): Promise<void> {
    if (flushing) {
        return;
    }

    flushing = true;

    try {
        const pending = readPending();

        if (pending.length === 0) {
            return;
        }

        // Cleared up front and put back on failure, so a report is never sent twice by two
        // overlapping flushes.
        writePending([]);

        const unsent: SaveReport[] = [];

        for (const report of pending) {
            try {
                const response = await fetch(ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(report),
                    keepalive: true,
                });

                if (!response.ok) {
                    throw new Error(`status ${response.status}`);
                }
            } catch {
                unsent.push(report);
            }
        }

        if (unsent.length > 0) {
            writePending([...unsent, ...readPending()]);
        }
    } finally {
        flushing = false;
    }
}

export function reportSaveEvent(report: SaveReport): void {
    writePending([
        ...readPending(),
        {
            online: navigator.onLine,
            page_url: window.location.href,
            client_version: currentInertiaVersion(),
            ...report,
        },
    ]);

    flushSaveReports().catch(() => {});
}
