export type PublishProgress = {percent: number; message: string; updated_at: string};
export type PublishProgressReporter = (percent: number, message: string) => void;
const entries = new Map<string, PublishProgress>();
export function startPublishProgress(id?: string): PublishProgressReporter {
    if (!id) return () => undefined;
    if (!/^[a-f0-9-]{36}$/i.test(id)) throw new Error('INVALID_PROGRESS_ID');
    for (const [key, value] of entries) if (Date.now() - Date.parse(value.updated_at) > 3600000) entries.delete(key);
    if (entries.size >= 1000 && !entries.has(id)) entries.delete(entries.keys().next().value!);
    const report: PublishProgressReporter = (percent, message) => {
        entries.set(id, {percent: Math.max(entries.get(id)?.percent ?? 0, Math.min(85, Math.max(0, Math.floor(percent)))), message, updated_at: new Date().toISOString()});
    };
    report(10, 'Membuka library SoundOn');
    return report;
}
export function getPublishProgress(id?: string): PublishProgress | undefined {
    return id ? entries.get(id) : undefined;
}
export function readPublishProgress(ids: unknown): Record<string, PublishProgress> {
    const result: Record<string, PublishProgress> = {};
    if (!Array.isArray(ids)) return result;
    for (const id of ids.slice(0,100)) if (typeof id === 'string') {
        const value=entries.get(id);
        if (value && Date.now()-Date.parse(value.updated_at)<=3600000) result[id]=value;
    }
    return result;
}
