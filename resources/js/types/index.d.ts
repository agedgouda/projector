import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

declare global {
    export interface User {
        id: number;
        first_name: string;
        last_name: string;
        name: string;
        email: string;
        avatar?: string;
        email_verified_at: string | null;
        roles: string[];
        clients: string[];
        permissions: string[];
    }

    export interface Auth {
        user: User;
        [key: string]: any;
    }

    export interface ProjectDocument {
        id: string | number | null;
        project_id: string;
        parent_id: string | null;
        name: string;
        type: string;
        content: string | null;

        // --- NEW ACTIONABLE COLUMNS ---
        status: 'todo' | 'in_progress' | 'done';
        creator_id: number | null;
        editor_id: number | null;
        assignee_id: number | null;

        // --- NEW OPTIONAL RELATIONSHIPS ---
        // These appear when you use ->with() in Laravel
        creator?: User;
        editor?: User;
        assignee?: User;

        embedding: any | null;
        metadata: {
            criteria?: string[];
            error?: string;       // Added for the AI failure tracking
            failed_at?: string;  // Added for the AI failure tracking
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

    /**
     * This defines the shape of all props passed from HandleInertiaRequests.php
     * The [key: string]: unknown ensures it satisfies the Inertia constraint.
     */
    export type AppPageProps<
        T extends Record<string, unknown> = Record<string, unknown>,
    > = T & {
        auth: Auth;
        name: string;
        quote: { message: string; author: string };
        sidebarOpen: boolean;
        requirementStatus: RequirementStatus[];
        flash: {
            success: string | null;
            error: string | null;
            aiResults?: any;
        };
        [key: string]: unknown;
    };
    export interface Task {
        id: string | null;
        project_id: string;
        document_id: string | null;
        assignee_id: number | null;
        title: string;
        description: string;
        status: 'todo' | 'in_progress' | 'done' | 'backlog';
        priority: 'low' | 'medium' | 'high';
        due_at: string | null;
    }
}

// These are exported normally for use in component props/definitions
export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    hidden?: boolean;
}

export type BreadcrumbItemType = BreadcrumbItem;

export {};
