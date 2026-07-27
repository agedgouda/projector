import type { AxiosError, AxiosResponse } from 'axios';

function goToLogin(): void {
    const isMobile = window.location.pathname.startsWith('/app');
    window.location.href = isMobile ? '/app/login?expired=1' : '/login?expired=1';
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

    if (typeof contentType === 'string' && !contentType.includes('application/json')) {
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
