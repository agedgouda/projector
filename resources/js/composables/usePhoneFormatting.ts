export function usePhoneFormatting() {
    /**
     * Formats a string into (###) ###-####
     */
    const formatPhoneNumber = (value: string) => {
        if (!value) return value;
        const phoneNumber = value.replace(/[^\d]/g, ''); // Remove all non-digits
        const length = phoneNumber.length;

        if (length < 4) return phoneNumber;
        if (length < 7) {
            return `(${phoneNumber.slice(0, 3)}) ${phoneNumber.slice(3)}`;
        }
        return `(${phoneNumber.slice(0, 3)}) ${phoneNumber.slice(3, 6)}-${phoneNumber.slice(6, 10)}`;
    };

    /**
     * Event handler that can be used on @input
     * Pass the current form value or a callback to update the form
     */
    const handlePhoneInput = (e: Event, callback: (val: string) => void) => {
        const target = e.target as HTMLInputElement;
        const formatted = formatPhoneNumber(target.value);

        // Update the target value immediately to prevent cursor jumping issues
        target.value = formatted;

        // Pass the formatted value back to the form
        callback(formatted);
    };

    return {
        formatPhoneNumber,
        handlePhoneInput
    };
}
