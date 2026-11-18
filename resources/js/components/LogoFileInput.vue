<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { resizeImage } from '@/lib/resizeImage';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: File | null;
    label?: string;
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [File | null];
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const previewUrl = ref<string | null>(null);

watch(
    () => props.modelValue,
    (file) => {
        if (file) {
            previewUrl.value = URL.createObjectURL(file);
        } else {
            previewUrl.value = null;
        }
    },
);

async function onFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (!file) {
        emit('update:modelValue', null);
        return;
    }
    emit('update:modelValue', await resizeImage(file));
}

function clear() {
    emit('update:modelValue', null);
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}
</script>

<template>
    <div class="space-y-3">
        <Label>{{ label ?? 'Logo' }}</Label>

        <div class="flex items-center gap-4">
            <div
                class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800"
            >
                <img
                    v-if="previewUrl"
                    :src="previewUrl"
                    alt="Logo preview"
                    class="size-full object-contain"
                />
                <span
                    v-else
                    class="px-2 text-center text-xs text-gray-400 dark:text-gray-500"
                    >No logo</span
                >
            </div>

            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="fileInput?.click()"
                    >
                        {{ modelValue ? 'Change' : 'Choose file' }}
                    </Button>
                    <Button
                        v-if="modelValue"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        @click="clear"
                    >
                        Remove
                    </Button>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    JPEG, PNG, WebP or GIF · max 5 MB
                </p>
                <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
            </div>
        </div>

        <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif"
            class="hidden"
            @change="onFileChange"
        />
    </div>
</template>
