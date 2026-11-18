import { transitionOptions as transitionOptionsRoute } from '@/actions/App/Http/Controllers/DocumentController';
import { redirectIfSessionExpiredError } from '@/lib/sessionExpiry';
import axios from 'axios';
import { ref } from 'vue';

interface AiTemplateOption {
    id: number;
    name: string;
}

interface TransitionOptions {
    aiTemplates: AiTemplateOption[];
}

const cache = new Map<string, TransitionOptions>();

/**
 * Loads the processing picker's options for a document: every workflow AI template the
 * organization hasn't turned off. Cached per project+document so repeated pickers on the same
 * page share one request.
 */
export function useTransitionOptions(projectId: string, documentId: string) {
    const aiTemplates = ref<AiTemplateOption[]>([]);
    const loading = ref(false);
    const loaded = ref(false);

    const cacheKey = `${projectId}:${documentId}`;

    const load = async () => {
        if (loaded.value || loading.value) return;

        const cached = cache.get(cacheKey);
        if (cached) {
            aiTemplates.value = cached.aiTemplates;
            loaded.value = true;
            return;
        }

        loading.value = true;
        try {
            const { data } = await axios.get<TransitionOptions>(
                transitionOptionsRoute.url({
                    project: projectId,
                    document: documentId,
                }),
            );
            aiTemplates.value = data.aiTemplates;
            cache.set(cacheKey, data);
            loaded.value = true;
        } catch (error) {
            if (redirectIfSessionExpiredError(error)) return;

            throw error;
        } finally {
            loading.value = false;
        }
    };

    return { aiTemplates, loading, loaded, load };
}
