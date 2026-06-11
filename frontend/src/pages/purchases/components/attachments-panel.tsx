import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { useToast } from '@/components/ui/toast';
import type { PurchaseOrderAttachment, PurchaseOrderDetail } from '@/types/purchases';
import { ATTACHMENT_LABELS } from '@/types/purchases';
import { Download, Paperclip, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

interface Props {
    po: PurchaseOrderDetail;
    onUpload: (id: string, file: File, type: string) => Promise<PurchaseOrderAttachment>;
    onDelete: (id: string, attachmentId: string) => Promise<void>;
    getUrl: (id: string, attachmentId: string, disposition?: 'inline' | 'attachment') => Promise<string>;
    onChange: () => Promise<void> | void;
}

const TYPES = ['invoice', 'delivery_note', 'payment_proof', 'other'] as const;

export function AttachmentsPanel({ po, onUpload, onDelete, getUrl, onChange }: Props) {
    const { showToast } = useToast();
    const fileRef = useRef<HTMLInputElement>(null);
    const [type, setType] = useState<(typeof TYPES)[number]>('invoice');
    const [busy, setBusy] = useState(false);
    const [fileName, setFileName] = useState<string | null>(null);
    const [pendingDelete, setPendingDelete] = useState<PurchaseOrderAttachment | null>(null);
    const [deleting, setDeleting] = useState(false);

    async function pickAndUpload() {
        const file = fileRef.current?.files?.[0];
        if (!file) {
            showToast('error', 'Primero elige un archivo.');
            return;
        }
        setBusy(true);
        try {
            await onUpload(po.id, file, type);
            showToast('success', `Adjunto "${file.name}" subido.`);
            if (fileRef.current) fileRef.current.value = '';
            setFileName(null);
            await onChange();
        } catch (err) {
            const msg = (err as { message?: string })?.message ?? 'No se pudo subir el archivo.';
            showToast('error', msg);
        } finally {
            setBusy(false);
        }
    }

    // Abre el adjunto vía URL temporal de S3. Abrimos la pestaña ANTES del
    // await (gesto del usuario) para esquivar el bloqueador de popups, y luego
    // le seteamos la URL firmada cuando llega.
    async function openAttachment(attachmentId: string, disposition: 'inline' | 'attachment') {
        const tab = window.open('', '_blank', 'noopener,noreferrer');
        try {
            const url = await getUrl(po.id, attachmentId, disposition);
            if (tab) {
                tab.location.href = url;
            } else {
                window.location.href = url;
            }
        } catch (err) {
            tab?.close();
            const msg = (err as { message?: string })?.message ?? 'No se pudo abrir el adjunto.';
            showToast('error', msg);
        }
    }

    async function confirmRemove() {
        if (!pendingDelete) return;
        setDeleting(true);
        try {
            await onDelete(po.id, pendingDelete.id);
            showToast('success', 'Adjunto eliminado.');
            await onChange();
            setPendingDelete(null);
        } catch (err) {
            const msg = (err as { message?: string })?.message ?? 'No se pudo eliminar.';
            showToast('error', msg);
        } finally {
            setDeleting(false);
        }
    }

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold">Adjuntos</h3>
                <span className="text-muted-foreground text-xs">PDF / JPG / PNG · máx 10 MB</span>
            </div>

            <div className="flex flex-wrap items-end gap-2">
                <div className="space-y-1">
                    <label className="text-xs">Tipo</label>
                    <select
                        value={type}
                        onChange={(e) => setType(e.target.value as (typeof TYPES)[number])}
                        className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                    >
                        {TYPES.map((t) => (
                            <option key={t} value={t}>
                                {ATTACHMENT_LABELS[t]}
                            </option>
                        ))}
                    </select>
                </div>
                <input
                    ref={fileRef}
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    className="sr-only"
                    onChange={(e) => setFileName(e.target.files?.[0]?.name ?? null)}
                />
                <Button type="button" size="sm" variant="outline" onClick={() => fileRef.current?.click()} disabled={busy}>
                    <Paperclip className="mr-1 h-3.5 w-3.5" /> Elegir archivo
                </Button>
                <span className="text-muted-foreground max-w-[12rem] truncate text-xs" title={fileName ?? undefined}>
                    {fileName ?? 'Ningún archivo elegido'}
                </span>
                <Button size="sm" onClick={pickAndUpload} disabled={busy || !fileName}>
                    <Upload className="mr-1 h-3.5 w-3.5" /> Subir
                </Button>
            </div>

            {po.attachments.length === 0 ? (
                <p className="text-muted-foreground text-xs">Sin adjuntos.</p>
            ) : (
                <ul className="divide-y rounded-md border text-sm">
                    {po.attachments.map((a) => (
                        <li key={a.id} className="flex items-center justify-between gap-2 px-3 py-2">
                            <button
                                type="button"
                                onClick={() => void openAttachment(a.id, 'inline')}
                                className="min-w-0 flex-1 text-left"
                                title="Abrir en una pestaña nueva"
                            >
                                <div className="truncate font-medium underline-offset-2 hover:underline">{a.original_name}</div>
                                <div className="text-muted-foreground text-xs">
                                    {ATTACHMENT_LABELS[a.type]} · {(a.size_bytes / 1024).toFixed(1)} KB
                                </div>
                            </button>
                            <Button size="sm" variant="ghost" title="Descargar" onClick={() => void openAttachment(a.id, 'attachment')}>
                                <Download className="h-3.5 w-3.5" />
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => setPendingDelete(a)}>
                                <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            <ConfirmDialog
                open={pendingDelete !== null}
                title="¿Eliminar adjunto?"
                message={pendingDelete ? `Se eliminará "${pendingDelete.original_name}". Esta acción no se puede deshacer.` : ''}
                confirmLabel="Eliminar"
                loading={deleting}
                onConfirm={() => void confirmRemove()}
                onCancel={() => setPendingDelete(null)}
            />
        </div>
    );
}
