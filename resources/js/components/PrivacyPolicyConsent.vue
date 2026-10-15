<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { privacy } from '@/routes';

defineProps<{
    error?: string;
    tabindex?: number;
}>();
</script>

<template>
    <div class="grid gap-1.5">
        <label for="privacy_policy" class="flex items-start gap-2.5 text-sm text-muted-foreground">
            <Checkbox id="privacy_policy" name="privacy_policy" value="1" required :tabindex="tabindex" class="mt-0.5" />
            <span>
                I agree to the
                <a :href="privacy.url()" target="_blank" rel="noopener" class="text-foreground underline underline-offset-4">Privacy Policy</a>
            </span>
        </label>
        <InputError :message="error" />
    </div>
</template>
