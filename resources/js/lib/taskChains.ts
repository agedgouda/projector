// Ordering tasks as chains: each task listed right after the one it waits on, one level deeper
// (a task waits on at most one other, so chains form trees — see TaskChainScheduler). Shared by
// the Tasks tab's List (TaskChainList.vue) and the Task Report (TaskReportTable.vue).

export type ChainItem = {
    id: string | number;
    name: string;
    predecessor_id?: string | null;
    start_at?: string | null;
    due_at?: string | null;
};

export type ChainRow<T extends ChainItem> = {
    item: T;
    depth: number;
    hasFollowers: boolean;
};

const startKey = (item: ChainItem) =>
    (item.start_at ?? item.due_at ?? '').slice(0, 10) || '9999-99-99';

// Earliest first; undated last; then by name.
export const byChainDate = (a: ChainItem, b: ChainItem) =>
    startKey(a).localeCompare(startKey(b)) || a.name.localeCompare(b.name);

export const followersByPredecessor = <T extends ChainItem>(
    items: T[],
): Map<string, T[]> => {
    const map = new Map<string, T[]>();
    for (const item of items) {
        if (!item.predecessor_id) continue;
        const list = map.get(item.predecessor_id) ?? [];
        list.push(item);
        map.set(item.predecessor_id, list);
    }
    return map;
};

/**
 * The given items in chain order. An item whose predecessor isn't among them (filtered out,
 * or never linked) starts its own chain at the top level rather than disappearing. Items in
 * `collapsedIds` are listed but their followers aren't.
 */
export const orderAsChains = <T extends ChainItem>(
    items: T[],
    collapsedIds: Set<string> = new Set(),
): ChainRow<T>[] => {
    const ids = new Set(items.map((item) => String(item.id)));
    const followers = followersByPredecessor(items);
    const roots = items
        .filter((item) => !item.predecessor_id || !ids.has(item.predecessor_id))
        .sort(byChainDate);

    const rows: ChainRow<T>[] = [];
    const seen = new Set<string>();

    const walk = (item: T, depth: number) => {
        const id = String(item.id);
        if (seen.has(id)) return;
        seen.add(id);
        const next = [...(followers.get(id) ?? [])].sort(byChainDate);
        rows.push({ item, depth, hasFollowers: next.length > 0 });
        if (collapsedIds.has(id)) return;
        next.forEach((follower) => walk(follower, depth + 1));
    };
    roots.forEach((root) => walk(root, 0));

    return rows;
};
