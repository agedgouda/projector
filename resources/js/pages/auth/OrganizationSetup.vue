<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Form, Head } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';

const selectedTier = ref<'free' | 'pro'>('free');

const userCountOptions = Array.from({ length: 17 }, (_, i) => i + 3); // 3–19
const selectedUserCount = ref<number | '20+'>( 3);
const customUserCount = ref(20);

const isCustomCount = computed(() => selectedUserCount.value === '20+');
const customUserCountError = ref('');
const customUserCountInput = ref<InstanceType<typeof Input> | null>(null);

const plannedUserCount = computed(() =>
    isCustomCount.value ? customUserCount.value : Number(selectedUserCount.value)
);

const validateCustomUserCount = () => {
    if (customUserCount.value < 20) {
        customUserCountError.value = 'Please enter 20 or more users.';
        customUserCountInput.value?.$el?.focus();
    } else {
        customUserCountError.value = '';
    }
};

watch(selectedTier, (tier) => {
    if (tier !== 'pro') {
        selectedUserCount.value = 3;
    }
});

const tiers = [
    {
        key: 'free' as const,
        label: 'Free',
        price: '$0 / month',
        features: ['1 user', '1 client', '1 project', '25 AI documents / month'],
    },
    {
        key: 'pro' as const,
        label: 'Pro',
        price: '$25 / user / month',
        features: ['Unlimited users', 'Unlimited clients', 'Unlimited projects', '100 AI documents / month'],
    },
];
</script>

<template>
    <AuthBase
        title="Set up your organization"
        description="Create a workspace to manage your clients and projects."
    >
        <Head title="Organization Setup" />

        <Form
            method="post"
            action="/organization/setup"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">Organization name</Label>
                    <Input
                        id="name"
                        name="name"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="organization"
                        placeholder="Acme Inc."
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label>Plan</Label>
                    <div class="grid grid-cols-2 gap-3">
                        <button
                            v-for="tier in tiers"
                            :key="tier.key"
                            type="button"
                            @click="selectedTier = tier.key"
                            class="relative rounded-xl border-2 p-4 text-left transition-all focus:outline-none"
                            :class="selectedTier === tier.key
                                ? 'border-projector-primary-600 bg-projector-primary-50/60 dark:bg-projector-primary-900/20'
                                : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-black text-slate-900 dark:text-white">{{ tier.label }}</span>
                                <CheckCircle2
                                    v-if="selectedTier === tier.key"
                                    class="h-4 w-4 text-projector-primary-600"
                                />
                            </div>
                            <p class="text-xs font-semibold text-projector-primary-600 dark:text-projector-primary-400 mb-2">{{ tier.price }}</p>
                            <ul class="space-y-1">
                                <li v-for="feature in tier.features" :key="feature" class="text-[11px] text-slate-500 dark:text-slate-400">
                                    {{ feature }}
                                </li>
                            </ul>
                        </button>
                    </div>
                    <input type="hidden" name="membership_tier" :value="selectedTier" />
                    <InputError :message="errors.membership_tier" />
                </div>

                <div v-if="selectedTier === 'pro'" class="grid gap-2">
                    <Label for="user_count">How many users will you be inviting?</Label>
                    <select
                        id="user_count"
                        :value="selectedUserCount"
                        @change="selectedUserCount = ($event.target as HTMLSelectElement).value === '20+' ? '20+' : Number(($event.target as HTMLSelectElement).value)"
                        class="h-11 w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 text-sm font-medium text-slate-900 dark:text-slate-100 focus:ring-4 focus:ring-projector-primary-500/10 outline-none transition-all"
                    >
                        <option v-for="n in userCountOptions" :key="n" :value="n">{{ n }}</option>
                        <option value="20+">20+</option>
                    </select>

                    <div v-if="isCustomCount" class="mt-1">
                        <Input
                            ref="customUserCountInput"
                            v-model.number="customUserCount"
                            type="number"
                            :min="20"
                            placeholder="Enter number of users"
                            class="h-11 rounded-xl"
                            @blur="validateCustomUserCount"
                        />
                        <InputError :message="customUserCountError" class="mt-1" />
                    </div>

                    <input type="hidden" name="planned_user_count" :value="plannedUserCount" />
                    <InputError :message="errors.planned_user_count" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full cursor-pointer"
                    :tabindex="2"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Create organization
                </Button>
            </div>
        </Form>
    </AuthBase>
</template>
