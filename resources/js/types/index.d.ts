import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

// 1. Move everything inside this block
declare global {
    export interface Auth {
        user: User;
    }

    export interface User {
        id: number;
        name: string;
        email: string;
        avatar?: string;
        email_verified_at: string | null;
        created_at: string;
        updated_at: string;
    }

    export interface ProjectDocument {
        id: string;
        project_id: string;
        parent_id: string | null;
        name: string;
        type: string;
        content: string | null;
        embedding: any | null;
        metadata: {
            criteria?: string[];
            [key: string]: any;
        } | null;
        processed_at: string | null;
        created_at: string;
        updated_at: string;
    }

    export interface RequirementStatus {
        label: string;
        key: string;
        required: boolean;
        documents: ProjectDocument[];
        isUploaded: boolean;
    }

    export interface DocumentVectorizedEvent {
        document: ProjectDocument;
    }

    // This ensures Inertia props are typed globally
    export type AppPageProps<
        T extends Record<string, unknown> = Record<string, unknown>,
    > = T & {
        name: string;
        quote: { message: string; author: string };
        auth: Auth;
        sidebarOpen: boolean;
        requirementStatus: RequirementStatus[];
        flash: { success: string | null; error: string | null };
    };
}

// 2. These can stay outside or inside, but usually stay here for Lucide compatibility
export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
}

export type BreadcrumbItemType = BreadcrumbItem;

export {};
