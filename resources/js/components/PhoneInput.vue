<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    modelValue: string | null | undefined;
}>();

const emit = defineEmits(['update:modelValue']);

const formatPhone = (value: string) => {
    if (!value) return '';
    const digits = value.replace(/\D/g, '');
    if (digits.length <= 3) return digits ? `(${digits}` : '';
    if (digits.length <= 6) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
    return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6, 10)}`;
};

const internalValue = computed({
    get: () => formatPhone(props.modelValue ?? ''),
    set: (val) => {
        // Emit only the digits to the parent (clean data)
        const cleanDigits = val.replace(/\D/g, '').slice(0, 10);
        emit('update:modelValue', cleanDigits);
    }
});
</script>

<template>
    <Input
        v-model="internalValue"
        type="text"
        placeholder="(###) ###-####"
        maxlength="14"
    />
</template>
