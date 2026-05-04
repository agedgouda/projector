<script setup lang="ts">
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Form, Head } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';

const selectedTier = ref<'free' | 'pro'>('free');

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
                                ? 'border-indigo-600 bg-indigo-50/60 dark:bg-indigo-900/20'
                                : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-black text-slate-900 dark:text-white">{{ tier.label }}</span>
                                <CheckCircle2
                                    v-if="selectedTier === tier.key"
                                    class="h-4 w-4 text-indigo-600"
                                />
                            </div>
                            <p class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 mb-2">{{ tier.price }}</p>
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
