// @/lib/flat-ui.ts
//
// Shared Tailwind class tokens for the flat "outline list" design language
// (compact rows, single quiet hover-highlight, tinted-bg + accent-bar selection,
// hover-revealed icon actions, underline search fields). Centralizing these
// means a future visual tweak — like the hover-color value, which has already
// needed retuning twice based on contrast feedback — happens in one place.

export const FLAT_ROW_HOVER = 'hover:bg-slate-200/80 dark:hover:bg-slate-700/60';

export const FLAT_ROW_SELECTED = 'bg-projector-primary-50 dark:bg-projector-primary-950/20';

export const FLAT_ROW_ACCENT_BAR = 'absolute left-0 top-1.5 bottom-1.5 w-[3px] rounded-full bg-projector-primary-600';

export const FLAT_ACTION_BUTTON =
    'h-7 w-7 flex items-center justify-center rounded-md text-slate-400 hover:text-projector-primary-600 hover:bg-projector-primary-50 dark:hover:bg-projector-primary-950/30 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-colors';

export const FLAT_SEARCH_ICON = 'absolute left-2 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 group-focus-within:text-projector-primary-500 transition-colors';

export const FLAT_SEARCH_INPUT =
    'h-9 pl-8 pr-2 bg-transparent border-0 border-b border-slate-200 dark:border-slate-800 rounded-none shadow-none text-[13px] placeholder:text-slate-400 focus-visible:ring-0 focus-visible:border-projector-primary-400 transition-colors';
