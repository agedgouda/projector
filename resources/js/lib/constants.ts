export const STATUS_LABELS: Record<string, string> = {
    todo: 'To Do',
    backlog: 'Backlog',
    in_progress: 'In Progress',
    done: 'Done'
};

export const statusClasses: Record<string, string> = {
    todo: 'bg-slate-100 text-slate-500',
    backlog: 'bg-gray-100 text-gray-400',
    in_progress: 'bg-indigo-50 text-indigo-600',
    done: 'bg-emerald-50 text-emerald-600'
};

export const statusDotClasses: Record<string, string> = {
    todo: 'bg-slate-400 shadow-[0_0_8px_rgba(148,163,184,0.4)]',
    backlog: 'bg-gray-300 shadow-none',
    in_progress: 'bg-indigo-500 shadow-[0_0_8px_rgba(99,102,241,0.4)]',
    done: 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]'
};

export const PRIORITY_LABELS: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High'
};

export const priorityClasses: Record<string, string> = {
    low: 'bg-slate-100 text-slate-600 border-slate-200',
    medium: 'bg-blue-50 text-blue-700 border-blue-100',
    high: 'bg-orange-50 text-orange-700 border-orange-200'
};

export const priorityDotClasses: Record<string, string> = {
    low: 'bg-slate-400 shadow-[0_0_8px_rgba(148,163,184,0.3)]',
    medium: 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.4)]',
    high: 'bg-orange-500 shadow-[0_0_8px_rgba(249,115,22,0.4)]'
};
