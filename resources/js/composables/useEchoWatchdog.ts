import { ref, onMounted } from 'vue';
import { echo } from '@laravel/echo-vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import { useProcessingReconciler } from '@/composables/useProcessingReconciler';

const BAD_STATES = ['disconnected', 'failed', 'unavailable'];
const TOAST_DELAY_MS = 5000;

export function useEchoWatchdog(getProjectId: () => string | number | undefined) {
    const connectionStatus = ref('initializing');
    let toastTimer: ReturnType<typeof setTimeout> | null = null;
    let wasBad = false;

    const { refresh: reconcileProcessingStatus } = useProcessingReconciler();

    const handleStatusChange = async (newStatus: string) => {
        if (!BAD_STATES.includes(newStatus)) {
            if (toastTimer) {
                clearTimeout(toastTimer);
                toastTimer = null;
            }

            // Recovering from an actual drop (not the initial connect-on-mount, which has
            // nothing to have missed yet) — the socket may have missed a broadcast while it
            // was down, so check the server's truth once immediately instead of waiting on
            // whatever's left of useProcessingReconciler.ts's own delay/interval.
            if (wasBad) {
                wasBad = false;
                reconcileProcessingStatus();
            }

            return;
        }

        wasBad = true;

        toastTimer = setTimeout(() => {
            toast.warning('Live updates temporarily unavailable. Reconnecting…', {
                duration: 8000,
            });
        }, TOAST_DELAY_MS);

        try {
            await axios.post('/log-connection-issue', {
                state: newStatus,
                project_id: getProjectId(),
                url: window.location.href,
            });
        } catch {
            // silently ignore
        }

        const connector = echo()?.connector as any;
        if (connector?.pusher) {
            connector.pusher.connect();
        }
    };

    onMounted(() => {
        const connector = echo()?.connector as any;
        if (!connector?.pusher) return;

        const pusher = connector.pusher;
        connectionStatus.value = pusher.connection.state;

        pusher.connection.bind('state_change', (states: { current: string }) => {
            connectionStatus.value = states.current;
            void handleStatusChange(states.current);
        });
    });

    return { connectionStatus };
}
