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

    export interface Client {
        id: string; // UUID
        company_name: string;
        contact_name: string;
        contact_phone: string;
        users?: User[];
        projects?: Project[];
        created_at: string;
        updated_at: string;
    }

    export interface ProjectType {
        id: string; // UUID
        name: string;
        icon: string;
        workflow?: any[];
        document_schema?: any[];
        created_at: string;
        updated_at: string;
    }

    export interface Project {
        id: string; // UUID
        name: string;
        description: string | null;
        client_id: string;
        project_type_id: string | null;

        // --- Relationships ---
        client: Client;
        type: ProjectType; // Matches Laravel naming convention
        documents?: ProjectDocument[];

        // Timestamps
        created_at: string;
        updated_at: string;

        // Meta/Counts
        documents_count?: number;
        tasks: Task[];
    }

    export interface ProjectDocument {
        id: string; // UUID
        project_id: string;
        parent_id: string | null;
        name: string;
        type: string;
        content: string | null;

        // --- Actionable Columns ---
        status: 'todo' | 'in_progress' | 'done';
        creator_id: number | null;
        editor_id: number | null;
        assignee_id: number | null;

        // --- Relationships ---
        project: Project;
        creator: User;
        editor?: User;
        assignee?: User;
        children?: ProjectDocument[]; // For parent/child relationships

        embedding: any | null;
        metadata: {
            criteria?: string[];
            error?: string;
            failed_at?: string;
            [key: string]: any;
        } | null;

        processed_at: string | null;
        created_at: string;
        updated_at: string;
    }

    export interface DocumentThread {
        root: ProjectDocument;
        children: ProjectDocument[];
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
            message: string | null;
            aiResults?: any;
        };
        [key: string]: unknown;
    };

    export type TaskStatus = 'todo' | 'in_progress' | 'review' | 'done';
    export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';

    export interface Task {
        // Primary & Foreign Keys (UUIDs)
        id: number;
        project_id: string;
        assignee_id: number | null;
        document_id: string | null;

        // Task Content
        title: string;
        description: string | null;

        // Workflow State
        status: TaskStatus;
        priority: TaskPriority;

        // Dates
        due_at: string | null; // ISO string from Laravel backend
        created_at: string;
        updated_at: string;

        // Optional Eager-Loaded Relationships
        project: Project;
        document?: ProjectDocument;
        assignee?: User;
    }

    export interface FlatTask {
        id?: number | string;
        project_id: string;
        document_id: string | null;
        title: string;
        description: string | null;
        status: any;
        priority: any;
        assignee_id: number | null;
        due_at: string | null;
        [key: string]: any; // This "catch-all" prevents the "breaks other pages" issue
    }

    // Update your ProjectGroup interface for the Dashboard as well
    export interface ProjectTaskGroup {
        project: Project & {
            client: {
                users: User[]
            }
        };
        tasks: Task[];
    }
}

// Module-level exports
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
