/**
 * Whether the current viewer is the one whose action started a document/org-document's
 * currently-in-flight AI processing — used to gate AiProcessingHeader banners to only the
 * triggering user. Falls back to whoever created the row when there's no explicit trigger
 * attribution (e.g. processing cascaded from an observer or a webhook-triggered import, with no
 * interactive user in the request — see ProcessingStatusController.php's PHP-side equivalent
 * reasoning, though that endpoint doesn't need this specific check).
 */
export function isProcessingMine(
    doc: {
        processing_triggered_by_user_id?: number | string | null;
        creator_id?: number | string | null;
    } | null | undefined,
    currentUserId: number,
): boolean {
    if (!doc) return false;

    const owner = doc.processing_triggered_by_user_id ?? doc.creator_id ?? null;
    if (owner == null) return false;

    return Number(owner) === currentUserId;
}
