<script setup lang="ts">
import { useForm, router, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Plus, Briefcase, ExternalLink } from 'lucide-vue-next';
import userRoutes from '@/routes/users/index';
import ResourceCard from '@/components/ResourceCard.vue';

const props = defineProps<{
    user: any;
    allClients: { id: string, company_name: string }[];
}>();

const form = useForm({
    client_id: '',
});

/**
 * Assigns a new client to the user.
 */
const assignClient = () => {
    if (!form.client_id) return;

    const newIds = [...props.user.clients, form.client_id];

    router.put(userRoutes.update(props.user.id).url, {
        client_ids: newIds
    }, {
        preserveScroll: true,
        onSuccess: () => form.reset('client_id')
    });
};

/**
 * Removes a client from the user's assignment.
 */
const removeClient = (clientId: string) => {
    const remainingIds = props.user.clients.filter((id: string) => id !== clientId);

    router.put(userRoutes.update(props.user.id).url, {
        client_ids: remainingIds
    }, {
        preserveScroll: true
    });
};

/**
 * Resolves the company name from the ID.
 */
const getClientName = (id: string) => {
    return props.allClients.find(c => c.id === id)?.company_name || 'Unknown Client';
};
</script>

<template>
    <div class="space-y-4 w-full">
        <div class="flex gap-2 w-full">
            <select
                v-model="form.client_id"
                class="flex-1 rounded-xl border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-sm font-medium h-10 px-3 outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all text-gray-900 dark:text-gray-100"
            >
                <option value="" disabled>Assign a client...</option>
                <option
                    v-for="client in allClients"
                    :key="client.id"
                    :value="client.id"
                    :disabled="user.clients.includes(client.id)"
                >
                    {{ client.company_name }}
                </option>
            </select>

            <Button
                @click="assignClient"
                :disabled="!form.client_id || form.processing"
                class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl h-10 px-4 transition-all active:scale-95"
            >
                <Plus class="w-4 h-4" />
            </Button>
        </div>

        <div class="w-full">
            <div class="space-y-2 w-full">
                <div v-if="user.clients.length === 0" class="p-8 text-center border-2 border-dashed border-gray-100 dark:border-gray-800 rounded-2xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">
                        No Clients Assigned
                    </p>
                </div>

                <ResourceCard
                    v-for="clientId in user.clients"
                    :key="clientId"
                    :title="getClientName(clientId)"
                    :show-delete="true"
                    @delete="removeClient(clientId)"
                    class="w-full"
                >
                    <template #icon>
                        <div class="p-1.5 rounded-lg bg-gray-50 dark:bg-gray-800 text-gray-400">
                            <Briefcase class="w-3.5 h-3.5" />
                        </div>
                    </template>

                    <template #actions>
                        <Link
                            :href="`/clients/${clientId}`"
                            class="p-2 text-gray-400 hover:text-indigo-600 transition-colors"
                        >
                            <ExternalLink class="w-3.5 h-3.5" />
                        </Link>
                    </template>
                </ResourceCard>
            </div>
        </div>
    </div>
</template>
