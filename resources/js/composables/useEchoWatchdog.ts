import { ref, onMounted } from 'vue';
import { echo } from '@laravel/echo-vue';
import axios from 'axios';

const BAD_STATES = ['disconnected', 'failed', 'unavailable'];

export function useEchoWatchdog(getProjectId: () => string | number | undefined) {
    const connectionStatus = ref('initializing');

    const handleStatusChange = async (newStatus: string) => {
        if (!BAD_STATES.includes(newStatus)) return;

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
