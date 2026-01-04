<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Plus, X } from 'lucide-vue-next';
import userRoutes from '@/routes/users/index';
import { router } from '@inertiajs/vue3';

const props = defineProps<{
    user: any;
    allClients: { id: string, company_name: string }[];
}>();

const form = useForm({
    client_id: '',
});

/**
 * Assigns a new client.
 * We send the new full list of client_ids to the generic update route.
 */
const assignClient = () => {
    if (!form.client_id) return;

    const newIds = [...props.user.clients, form.client_id];

    // Using router.put to match Option 1 and the specific Wayfinder property access
    router.put(userRoutes.update(props.user.id).url, {
        client_ids: newIds
    }, {
        preserveScroll: true,
        onSuccess: () => form.reset('client_id')
    });
};

/**
 * Removes a client.
 * We send the filtered list to the same generic update route.
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
 * Helper to resolve the company name from the ID
 * since the user object only stores the ID strings.
 */
const getClientName = (id: string) => {
    return props.allClients.find(c => c.id === id)?.company_name || 'Unknown Client';
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex gap-2">
            <select
                v-model="form.client_id"
                class="flex-1 rounded-xl border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-sm font-medium h-10 px-3 outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all"
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

        <div class="flex flex-wrap gap-2 min-h-[40px]">
            <div
                v-for="clientId in user.clients"
                :key="clientId"
                class="flex items-center gap-2 pl-3 pr-1 py-1 bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-xl shadow-sm hover:border-indigo-200 transition-colors"
            >
                <span class="text-[11px] font-bold text-gray-700 dark:text-gray-300">
                    {{ getClientName(clientId) }}
                </span>
                <button
                    @click="removeClient(clientId)"
                    class="p-1 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                >
                    <X class="w-3 h-3" />
                </button>
            </div>

            <p v-if="user.clients.length === 0" class="text-[11px] text-gray-400 italic py-2">
                No clients currently assigned.
            </p>
        </div>
    </div>
</template>
