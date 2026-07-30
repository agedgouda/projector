/**
 * Kanban columns are per-project now (see KanbanColumnDef), so label/color lookups are
 * keyed by each column's stored `color` name rather than by a fixed status string.
 */
export const KANBAN_COLOR_PALETTE = ['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal'] as const;

export const kanbanClasses: Record<string, string> = {
    slate: 'bg-slate-100 text-slate-500',
    red: 'bg-red-50 text-red-600',
    amber: 'bg-amber-50 text-amber-600',
    emerald: 'bg-emerald-50 text-emerald-600',
    blue: 'bg-blue-50 text-blue-600',
    purple: 'bg-purple-50 text-purple-600',
    pink: 'bg-pink-50 text-pink-600',
    orange: 'bg-orange-50 text-orange-600',
    indigo: 'bg-indigo-50 text-indigo-600',
    teal: 'bg-teal-50 text-teal-600'
};

export const kanbanDotClasses: Record<string, string> = {
    slate: 'bg-slate-400 shadow-[0_0_8px_rgba(148,163,184,0.4)]',
    red: 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.4)]',
    amber: 'bg-amber-400 shadow-[0_0_8px_rgba(251,191,36,0.4)]',
    emerald: 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]',
    blue: 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.4)]',
    purple: 'bg-purple-500 shadow-[0_0_8px_rgba(168,85,247,0.4)]',
    pink: 'bg-pink-500 shadow-[0_0_8px_rgba(236,72,153,0.4)]',
    orange: 'bg-orange-500 shadow-[0_0_8px_rgba(249,115,22,0.4)]',
    indigo: 'bg-indigo-500 shadow-[0_0_8px_rgba(99,102,241,0.4)]',
    teal: 'bg-teal-500 shadow-[0_0_8px_rgba(20,184,166,0.4)]'
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


export const LLM_DRIVERS: AiDriverOption[] = [
    { value: '',       label: 'System Default' },
    { value: 'openai', label: 'OpenAI' },
    { value: 'gemini', label: 'Google Gemini' },
    { value: 'claude', label: 'Anthropic Claude' },
    { value: 'ollama', label: 'Ollama (self-hosted)' },
];

export const MEETING_PROVIDERS: { value: string; label: string }[] = [
    { value: '', label: 'None' },
    { value: 'zoom', label: 'Zoom' },
    { value: 'teams', label: 'Microsoft Teams' },
    { value: 'google_meet', label: 'Google Meet' },
    { value: 'slack', label: 'Slack' },
];

export const meetingProviderLabel = (provider: string | null): string | null =>
    MEETING_PROVIDERS.find(p => p.value === provider)?.label ?? null;

/** Claude is excluded — Anthropic has no public embeddings API. */
export const VECTOR_DRIVERS: AiDriverOption[] = [
    { value: '',       label: 'System Default' },
    { value: 'same',   label: 'Same as LLM Driver' },
    { value: 'openai', label: 'OpenAI' },
    { value: 'gemini', label: 'Google Gemini' },
    { value: 'ollama', label: 'Ollama (self-hosted)' },
];
