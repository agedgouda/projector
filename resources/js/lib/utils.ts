import { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function urlIsActive(
    urlToCheck: NonNullable<InertiaLinkProps['href']>,
    currentUrl: string,
) {
    return toUrl(urlToCheck) === currentUrl;
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

export function formatPhoneNumber(phoneNumberString: string | null | undefined): string {
    if (!phoneNumberString) return '';

    // Clean the input just in case it's not pure digits
    const cleaned = ('' + phoneNumberString).replace(/\D/g, '');

    // Match the groups for (###) ###-####
    const match = cleaned.match(/^(\d{3})(\d{3})(\d{4})$/);

    if (match) {
        return `(${match[1]}) ${match[2]}-${match[3]}`;
    }

    // Fallback: If it's not 10 digits, just return the original digits
    return phoneNumberString;
}

export const formatDate = (dateString: string | null) => {
    if (!dateString) return '';

    const date = new Date(dateString);
    const now = new Date();

    // Calculate difference in seconds
    const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);

    // If it happened in the last hour, show minutes
    if (diffInSeconds < 3600) {
        const mins = Math.floor(diffInSeconds / 60);
        return mins <= 1 ? 'Just now' : `${mins}m ago`;
    }

    // If it happened today, show the time
    if (date.toDateString() === now.toDateString()) {
        return date.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }

    // Otherwise, show the date
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric'
    }).format(date);
};
