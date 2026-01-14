import { useEcho } from '@laravel/echo-vue';

/**
 * A generic wrapper for Laravel Echo listeners.
 * @param channelName - The private channel (e.g., `project.${id}`)
 * @param events - Array of event names (e.g., ['.document.vectorized'])
 * @param onUpdate - Callback function to handle the payload
 */
export function useRealtimeSync(
    channelName: string,
    events: string[],
    onUpdate: (payload: any) => void
) {
    useEcho(
        channelName,
        events,
        (payload: any) => {
            onUpdate(payload);
        },
        [channelName], // Re-bind if the channel ID changes
        'private'
    );
}
