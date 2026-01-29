// @/lib/kanban-theme.ts

import { CSSProperties } from "vue";

export const KANBAN_UI = {
    // Labels & Typography
    label: "text-[10px] font-black uppercase tracking-widest",
    subtleLabel: "text-[9px] font-black uppercase tracking-widest text-gray-400",

    // Card Styles
    // Added focus-visible and ring-offset for Keyboard Nav support
    card: "bg-white rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-all duration-200 cursor-grab active:cursor-grabbing outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2",
    cardTitle: "text-xs font-bold text-gray-900 leading-tight line-clamp-2",

    // Ghost/Placeholder Card (for when dragging)
    ghostCard: "bg-indigo-50/50 border-2 border-dashed border-indigo-200 rounded-xl opacity-50",

    // Header Styles
    columnHeader: "flex items-center justify-center gap-3 py-4",
    headerTitle: "text-[11px] font-black uppercase tracking-[0.15em] text-gray-500",

    // Avatar & Metadata
    avatar: "rounded-full border-2 border-white flex items-center justify-center overflow-hidden shadow-sm transition-transform duration-200 hover:scale-110",
    badge: "px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-tighter border",

    // Column Styles
    columnWrapper: "flex flex-col gap-4 min-h-[160px] bg-gray-50/40 rounded-[2rem] border border-dashed border-gray-200/60 p-4 relative",

    gridContainer: (columnCount: number): CSSProperties => ({
        display: 'grid',
        gap: '2rem', // gap-8
        gridTemplateColumns: `repeat(${columnCount}, minmax(0, 1fr))`,
        width: '100%',
    })


};

/**
 * Functional class compositions for Priority
 */
export const getPriorityStyles = (priority: string) => {
    const map: Record<string, string> = {
        high: "bg-red-50 text-red-600 border-red-100",
        medium: "bg-amber-50 text-amber-600 border-amber-100",
        low: "bg-emerald-50 text-emerald-600 border-emerald-100",
    };
    return map[priority?.toLowerCase()] || map.low;
};

/**
 * Deterministic Avatar Appearances
 * Keeps user colors consistent across the UI
 */
export const getAvatarAppearance = (id: number = 0) => {
    const variants = [
        "bg-indigo-50 text-indigo-700 border-indigo-100",
        "bg-emerald-50 text-emerald-700 border-emerald-100",
        "bg-amber-50 text-amber-700 border-amber-100",
        "bg-rose-50 text-rose-700 border-rose-100",
        "bg-sky-50 text-sky-700 border-sky-100",
    ];
    return variants[id % variants.length];
};


