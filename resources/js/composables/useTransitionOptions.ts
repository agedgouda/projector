import { ref } from 'vue';
import axios from 'axios';
import { transitionOptions as transitionOptionsRoute } from '@/actions/App/Http/Controllers/DocumentController';

export interface ProtocolOption {
    projectTypeId: string;
    name: string;
    toKey: string;
    aiTemplateId: number;
    singleOutput: boolean;
}

interface AiTemplateOption {
    id: number;
    name: string;
}

interface TransitionOptions {
    protocolOptions: ProtocolOption[];
    aiTemplates: AiTemplateOption[];
}

const cache = new Map<string, TransitionOptions>();

/**
 * Loads the either/or processing picker's options for a document: protocols that define their
 * own next step from this document's current type (path a), and every workflow AI template
 * (path b). Cached per project+document so repeated pickers on the same page share one request.
 */
export function useTransitionOptions(projectId: string, documentId: string) {
    const protocolOptions = ref<ProtocolOption[]>([]);
    const aiTemplates = ref<AiTemplateOption[]>([]);
    const loading = ref(false);
    const loaded = ref(false);

    const cacheKey = `${projectId}:${documentId}`;

    const load = async () => {
        if (loaded.value || loading.value) return;

        const cached = cache.get(cacheKey);
        if (cached) {
            protocolOptions.value = cached.protocolOptions;
            aiTemplates.value = cached.aiTemplates;
            loaded.value = true;
            return;
        }

        loading.value = true;
        try {
            const { data } = await axios.get<TransitionOptions>(
                transitionOptionsRoute.url({ project: projectId, document: documentId }),
            );
            protocolOptions.value = data.protocolOptions;
            aiTemplates.value = data.aiTemplates;
            cache.set(cacheKey, data);
            loaded.value = true;
        } finally {
            loading.value = false;
        }
    };

    return { protocolOptions, aiTemplates, loading, loaded, load };
}
