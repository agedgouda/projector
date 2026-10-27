import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Why a new recording can't be transcribed right now (the organization has used its month's
 * pooled transcription hours — see Organization::transcriptionLimitMessage()), or null when it
 * can. Only gates starting something new: a capture already running is always saved and
 * transcribed. The server checks the same thing again before any work starts.
 */
export function useTranscriptionLimit() {
    const page = usePage();

    return computed<string | null>(
        () => (page.props as any).orgMembership?.transcription_limit_message ?? null,
    );
}
