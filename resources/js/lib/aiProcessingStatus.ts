export interface ProcessingStatus {
    message: string;
    newProgress: number;
    isError: boolean;
    isSuccess: boolean;
}

/**
 * Classifies a `.DocumentProcessingUpdate` broadcast's `statusMessage`/`progress` into the
 * shape both useAiProcessing.ts (project-wide, multi-document) and useDocumentForm.ts
 * (single document) branch on — kept in one place so the success/error heuristic (string
 * matching on "error"/"failed"/"success", since that's all the broadcast payload gives us)
 * can't drift between the two.
 */
export function parseProcessingStatus(payload: { statusMessage?: unknown; progress?: unknown }): ProcessingStatus {
    const message = String(payload.statusMessage);
    const msg = message.toLowerCase();
    const newProgress = Number(payload.progress || 0);
    const isError = msg.includes('error') || msg.includes('failed');
    const isSuccess = (msg.includes('success') || newProgress === 100) && !isError;

    return { message, newProgress, isError, isSuccess };
}
