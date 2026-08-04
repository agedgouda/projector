import { goToLogin } from '@/lib/sessionExpiry';
import { logout } from '@/routes';
import sessionRoutes from '@/routes/session';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// How long before the server actually expires the session to show the warning modal.
// Must stay comfortably under the shortest realistic session lifetime so the countdown
// itself never runs past the real expiry.
const WARNING_BEFORE_MS = 2 * 60 * 1000;

// Activity resets are timestamp-only writes, but the listeners themselves (mousemove,
// scroll) fire far more often than that's useful — coalesce to at most once per interval.
const ACTIVITY_THROTTLE_MS = 5000;

const ACTIVITY_EVENTS = [
    'mousemove',
    'mousedown',
    'keydown',
    'scroll',
    'touchstart',
] as const;

export function useIdleSessionTimer() {
    const page = usePage<AppPageProps>();

    const showWarning = ref(false);
    const remainingSeconds = ref(0);
    const isExtending = ref(false);

    let lastActivityAt = Date.now();
    let lastActivityWrite = Date.now();
    let tickHandle: ReturnType<typeof setInterval> | null = null;

    const lifetimeMs = () =>
        (page.props.session?.lifetime_minutes ?? 120) * 60 * 1000;

    // Once the warning is showing, further passive activity (a stray mousemove, a
    // background tab scrolling) no longer silently resets the clock — from here the user
    // must make an explicit choice, or the countdown runs out on its own. This is what
    // makes the modal meaningful rather than just a flash that immediately re-hides itself.
    const recordActivity = () => {
        if (showWarning.value) return;

        const now = Date.now();
        if (now - lastActivityWrite < ACTIVITY_THROTTLE_MS) return;

        lastActivityWrite = now;
        lastActivityAt = now;
    };

    const performLogout = () => {
        router.flushAll();
        // The session is already gone or about to be by the time this runs, so the POST
        // itself may well fail (e.g. a dead CSRF token) — that's fine, the goal is just
        // to land back on the login page either way.
        router.post(
            logout().url,
            {},
            {
                onFinish: () => goToLogin(),
            },
        );
    };

    const tick = () => {
        const idleFor = Date.now() - lastActivityAt;
        const remaining = lifetimeMs() - idleFor;

        if (remaining <= 0) {
            if (tickHandle) clearInterval(tickHandle);
            performLogout();
            return;
        }

        if (remaining <= WARNING_BEFORE_MS) {
            showWarning.value = true;
            remainingSeconds.value = Math.ceil(remaining / 1000);
        }
    };

    const stayLoggedIn = async () => {
        isExtending.value = true;
        try {
            await axios.post(sessionRoutes.keepAlive.url());
            lastActivityAt = Date.now();
            lastActivityWrite = lastActivityAt;
            showWarning.value = false;
        } catch {
            // The keep-alive ping itself 401/419'd — the session is already gone server-side,
            // so there's nothing left to extend.
            performLogout();
        } finally {
            isExtending.value = false;
        }
    };

    const logoutNow = () => performLogout();

    onMounted(() => {
        ACTIVITY_EVENTS.forEach((event) =>
            window.addEventListener(event, recordActivity, { passive: true }),
        );
        tickHandle = setInterval(tick, 1000);
    });

    onBeforeUnmount(() => {
        ACTIVITY_EVENTS.forEach((event) =>
            window.removeEventListener(event, recordActivity),
        );
        if (tickHandle) clearInterval(tickHandle);
    });

    return {
        showWarning,
        remainingSeconds,
        isExtending,
        stayLoggedIn,
        logoutNow,
    };
}
