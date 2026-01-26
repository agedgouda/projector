import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

declare global {
    // --- AUTH & USER ---
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

    // --- CORE MODELS ---
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

    export interface DocumentSchemaItem {
        key: string;
        label: string;
        required: boolean;
        plural_label?: string;
    }

    export interface ProjectType {
        id: string; // UUID
        name: string;
        icon: string;
        workflow?: any[];
        document_schema?: DocumentSchemaItem[];
        created_at: string;
        updated_at: string;
    }

    export interface Project {
        id: string; // UUID
        name: string;
        description: string | null;
        client_id: string;
        project_type_id: string | null;

        // Relationships
        client: Client;
        type: ProjectType;
        documents?: ProjectDocument[];
        tasks: Task[];

        // Meta
        documents_count?: number;
        created_at: string;
        updated_at: string;
    }

    // --- TASKS, DISCUSSIONS & FLAT TYPES ---
    export type TaskStatus = 'todo' | 'in_progress' | 'review' | 'done' | 'backlog';
    export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';

    export interface DocumentMetadata {
    criteria: string[];
    raw_data?: {
        criteria?: string[];
        [key: string]: any;
    };
    error?: string;
    failed_at?: string;
    [key: string]: any;
}


    export interface ProjectDocument {
        id: string | number; // UUID in DB, but sometimes number in UI state
        project_id: string;
        parent_id: string | null;
        name: string;
        type: string;
        content: string | null;
        status: 'todo' | 'in_progress' | 'done';
        creator_id: number | null;
        editor_id: number | null;
        assignee_id: number | null;
        task_status: TaskStatus;
        priority: TaskPriority;
        due_at: string | null;

        // Relationships
        creator?: User;
        editor?: User;
        assignee?: User;
        project?: Project;
        children?: ProjectDocument[];

        embedding: any | null;
        metadata: DocumentMetadata;
        processed_at: string | null;
        created_at: string;
        updated_at: string;
    }

    export type UIProjectDocument = ProjectDocument & {
        processingError?: string | null;
        currentStatus?: string;
    };

    export interface DocumentFields extends DocumentForm {
        id: string;
        project_id: string;
    }
    export interface ExtendedDocument extends ProjectDocument {
        currentStatus?: string | null;      // Temporary AI status (e.g., "Analyzing...")
        hasError?: boolean;               // UI flag for highlighting rows
        processingError?: string | null;  // The specific error message from a failed AI Job
        children?: ExtendedDocument[];    // The recursive tree structure
        task_status: TaskStatus;
        priority: TaskPriority;
        due_at: string | null;
        tasks?: Task[];
        user?: User;
    }


    export interface DocumentForm {
        name: string;
        type: string;
        content: string | null;
        assignee_id: number | null;
        priority: TaskPriority;
        task_status: TaskStatus;
        due_at: string | null;
        metadata: DocumentMetadata;
    }



    export interface TaskFromDoc {
        id: string;                  // document ID
        title: string;
        description: string;
        assignee_id: number | null;
        assignee?: User | null;      // optional, may not exist
        due_at: string | null;
        priority: TaskPriority;
        status: TaskStatus;
        sourceType: string;          // document type
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
        [key: string]: any;
    }

    export interface Comment {
        id: number;
        user_id: number;
        body: string;
        commentable_type: string;
        commentable_id: number | string;
        created_at: string;
        updated_at: string;
        deleted_at?: string | null;
        user?: User;
    }

    // --- UI & STATE HELPERS ---
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

    export interface ProjectTaskGroup {
        project: Project & { client: { users: User[] } };
        tasks: Task[];
    }

    export interface DocumentVectorizedEvent {
        document: ProjectDocument;
    }

    export type AppPageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
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
}

// Module-level exports
export interface BreadcrumbItem { title: string; href: string; }
export type BreadcrumbItemType = BreadcrumbItem;

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    hidden?: boolean;
}

export {};
