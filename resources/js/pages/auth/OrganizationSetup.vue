<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Form, Head } from '@inertiajs/vue3';
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
