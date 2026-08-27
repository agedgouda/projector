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

export function formatPhoneNumber(
    phoneNumberString: string | null | undefined,
): string {
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

export function formatProjectLabel(project: {
    name: string;
    client_name?: string | null;
}): string {
    return project.client_name
        ? `${project.client_name}: ${project.name}`
        : project.name;
}

export function formatRoleName(role: string): string {
    return role.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function setPersistentCookie(name: string, value: string): void {
    document.cookie = `${name}=${value}; path=/; max-age=31536000; SameSite=Lax`;
}

// Strips HTML down to plain text for a short preview (e.g. a hover card) — mirrors
// ProjectCalendar.vue's own previewText(), generalized with an optional character limit.
export function htmlPreviewText(
    html: string | null | undefined,
    maxLength?: number,
): string {
    if (!html) return 'No description provided.';

    const div = document.createElement('div');
    div.innerHTML = html;
    const text = (div.textContent || '').trim().replace(/\s+/g, ' ');

    if (!text) return 'No description provided.';
    if (!maxLength || text.length <= maxLength) return text;

    return text.slice(0, maxLength).trimEnd() + '…';
}

// Formats a calendar date (a plain `YYYY-MM-DD` value, or a timestamp whose time-of-day is
// irrelevant — e.g. a stored due_at/start_at) as a local date without shifting it by a day.
// `new Date('2026-09-01')` parses the string as UTC midnight, and toLocaleDateString() then
// renders the *previous* day in any timezone behind UTC — parsing the y/m/d components
// directly into a local Date avoids that entirely.
export const formatDateOnly = (
    value: string | null | undefined,
    options?: { weekday?: boolean },
): string => {
    if (!value) return '';
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        ...(options?.weekday ? { weekday: 'short' as const } : {}),
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

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
            hour12: true,
        });
    }

    // Otherwise, show the date
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
    }).format(date);
};
