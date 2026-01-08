import { ref } from 'vue';

// This becomes a singleton that any component can import
export const globalAiState = ref({
    isProcessing: false
});
