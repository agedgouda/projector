import type { AxiosError, AxiosResponse } from 'axios';

const RETRY_LOGIN_REDIRECT_MS = 5000;

let attemptLoginRedirect: (() => Promise<void>) | null = null;

/**
 * Sends the tab to the login page — but only once it's visible and the server is actually
 * reachable. The idle timer fires this from a background tab, often the moment a laptop wakes
 * from sleep, before Wi-Fi is back; navigating right then left a pinned tab on Chrome's
 * offline (dinosaur) page until Chrome's own auto-reload kicked in. navigator.onLine alone
 * isn't enough (it's true as soon as Wi-Fi associates, before anything routes), so each
 * attempt pings /up first. Calling it again while a redirect is pending just retries now.
 */
export function goToLogin(): void {
    if (attemptLoginRedirect) {
        void attemptLoginRedirect();
        return;
    }

    const isMobile = window.location.pathname.startsWith('/app');
    const target = isMobile ? '/app/login?expired=1' : '/login?expired=1';
    let navigating = false;

    const attempt = async () => {
        if (
            navigating ||
            document.visibilityState !== 'visible' ||
            !navigator.onLine
        ) {
            return;
        }

        try {
            await fetch('/up', { method: 'HEAD', cache: 'no-store' });
        } catch {
            return;
        }

        if (navigating) {
            return;
        }

        navigating = true;
        clearInterval(retryHandle);
        document.removeEventListener('visibilitychange', attempt);
        window.removeEventListener('online', attempt);
        window.location.href = target;
    };

    const retryHandle = setInterval(attempt, RETRY_LOGIN_REDIRECT_MS);
    document.addEventListener('visibilitychange', attempt);
    window.addEventListener('online', attempt);
    attemptLoginRedirect = attempt;

    void attempt();
}

/**
 * A stale tab's CSRF token dies along with its session, and this app's exception handler
 * (bootstrap/app.php) turns every 419 into an unconditional redirect to the login page —
 * even for an XHR request. The browser follows that redirect transparently, so axios
 * resolves *successfully* with a 200 containing the login page's HTML instead of the JSON
 * the caller asked for, rather than throwing. Call this on the response of any raw axios
 * call to one of these JSON endpoints; it navigates away and returns true if that's what
 * happened, so the caller can bail out instead of treating a login page as a good response.
 */
export function redirectIfLoggedOut(response: AxiosResponse): boolean {
    const contentType = response.headers?.['content-type'];

    if (
        typeof contentType === 'string' &&
        !contentType.includes('application/json')
    ) {
        goToLogin();

        return true;
    }

    return false;
}

/**
 * The less common counterpart to redirectIfLoggedOut — covers a genuine 401/419 thrown as
 * an error rather than silently redirected-and-followed (e.g. the CSRF token is still valid
 * but the session itself was invalidated elsewhere, such as logging out in another tab).
 */
export function redirectIfSessionExpiredError(error: unknown): boolean {
    const status = (error as AxiosError | undefined)?.response?.status;

    if (status !== 401 && status !== 419) {
        return false;
    }

    goToLogin();

    return true;
}
