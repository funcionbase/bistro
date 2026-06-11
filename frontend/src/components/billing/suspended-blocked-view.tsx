import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { apiFetch } from '@/lib/api';
import type { Company } from '@/types';
import { AlertOctagon, FileText } from 'lucide-react';
import { useEffect, useState } from 'react';

import UploadPaymentProof from './upload-payment-proof';

interface PaymentProof {
    /**
     * UUID del comprobante (#193). Antes era `number` (BIGSERIAL) pero
     * abría puerta a enumeración secuencial — el backend ahora serializa el
     * UUID público en el campo `id` y mantiene el BIGSERIAL como PK interna.
     */
    id: string;
    invoice_ids: string[] | null;
    original_name: string;
    mime: string;
    size_bytes: number;
    status: 'submitted' | 'accepted' | 'rejected';
    reviewed_at: string | null;
    review_notes: string | null;
    created_at: string;
    /**
     * URL del endpoint `show` para abrir el comprobante. La calcula el
     * backend en el listado para evitar un round-trip adicional.
     */
    preview_url?: string;
}

const STATUS_LABEL: Record<PaymentProof['status'], string> = {
    submitted: 'En revisión',
    accepted: 'Aceptado',
    rejected: 'Rechazado',
};

const STATUS_CLASS: Record<PaymentProof['status'], string> = {
    submitted: 'bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]',
    accepted: 'bg-[color:var(--color-status-success)]/15 text-[color:var(--color-status-success)]',
    rejected: 'bg-[color:var(--color-status-critical)]/15 text-[color:var(--color-status-critical)]',
};

interface Props {
    activeCompany: Company;
    overdueTotal: number;
}

/**
 * Vista para empresas en `suspended` (#175). Reemplaza la vista normal de
 * facturación: oculta plan/historial detallado y enfoca al cliente en
 * regularizar el pago. Muestra: monto adeudado + datos de pago flexyflow +
 * formulario de comprobante + historial de comprobantes enviados.
 */
export default function SuspendedBlockedView({ activeCompany, overdueTotal }: Props) {
    const payment = activeCompany.flexyflow_payment;
    const [proofs, setProofs] = useState<PaymentProof[]>([]);
    // Comprobante seleccionado para preview en modal. `null` cuando no hay
    // popup abierto. El backend ya restringe `preview_url` por empresa
    // activa, así que cualquier proof del listado es seguro abrir.
    const [previewing, setPreviewing] = useState<PaymentProof | null>(null);

    const loadProofs = async () => {
        try {
            const res = await apiFetch('/api/v1/billing/payment-proofs');
            if (res.ok) {
                const data = await res.json();
                setProofs(data.data ?? []);
            }
        } catch {
            // silent
        }
    };

    useEffect(() => {
        loadProofs();
    }, []);

    return (
        <div className="space-y-6">
            <div className="flex items-start gap-3 rounded-xl border border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 p-4 text-[color:var(--color-status-critical)]">
                <AlertOctagon className="mt-0.5 h-6 w-6 shrink-0" />
                <div>
                    <p className="text-base font-bold">Tu cuenta está suspendida por mora prolongada.</p>
                    <p className="mt-1 text-sm">
                        Total adeudado: <span className="font-bold">$ {new Intl.NumberFormat('es-CO').format(overdueTotal)} COP</span>
                    </p>
                    <p className="mt-2 text-sm">Sube el comprobante del pago realizado y te reactivaremos automáticamente al validarlo.</p>
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="text-foreground text-base font-semibold">Datos de pago flexyflow</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-3 text-sm sm:grid-cols-2">
                    {payment?.breb_key && (
                        <div>
                            <p className="text-gray-500">Llave BREB</p>
                            <p className="text-primary font-mono text-base font-bold">{payment.breb_key}</p>
                        </div>
                    )}
                    {payment?.bank_name && (
                        <div>
                            <p className="text-gray-500">Banco</p>
                            <p className="text-foreground font-semibold">{payment.bank_name}</p>
                        </div>
                    )}
                    {payment?.account_number && (
                        <div>
                            <p className="text-gray-500">Número de cuenta ({payment.account_type ?? 'cuenta'})</p>
                            <p className="text-foreground font-mono font-semibold">{payment.account_number}</p>
                        </div>
                    )}
                    {payment?.account_holder && (
                        <div>
                            <p className="text-gray-500">Titular</p>
                            <p className="text-foreground font-semibold">{payment.account_holder}</p>
                        </div>
                    )}
                    {!payment?.breb_key && !payment?.account_number && (
                        <p className="text-sm text-gray-500 sm:col-span-2">
                            Los datos de pago no están disponibles en este momento. Escribe a soporte@flexyflow.co.
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-foreground text-base font-semibold">Subir comprobante de pago</CardTitle>
                </CardHeader>
                <CardContent>
                    <UploadPaymentProof onUploaded={loadProofs} />
                </CardContent>
            </Card>

            {proofs.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-foreground text-base font-semibold">Comprobantes enviados</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {proofs.map((p) => {
                            const isImage = typeof p.mime === 'string' && p.mime.startsWith('image/');
                            const isPdf = p.mime === 'application/pdf';
                            const previewUrl = p.preview_url;
                            const previewLabel = isImage ? 'Ver imagen' : isPdf ? 'Ver PDF' : 'Abrir archivo';
                            const openPreview = () => previewUrl && setPreviewing(p);
                            return (
                                <div key={p.id} className="flex items-start justify-between gap-3 rounded-md border border-gray-200 p-3 text-sm">
                                    {/* Thumbnail / icono. Click abre un Dialog del DS con la
                                        imagen o un iframe del PDF embebido para que el usuario
                                        revise el comprobante sin salir de /billing. */}
                                    {previewUrl ? (
                                        <button
                                            type="button"
                                            onClick={openPreview}
                                            title={previewLabel}
                                            className="focus:ring-ring shrink-0 rounded border border-gray-200 bg-white transition-colors hover:border-gray-300 focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
                                        >
                                            {isImage ? (
                                                <img
                                                    src={previewUrl}
                                                    alt={p.original_name}
                                                    loading="lazy"
                                                    className="h-16 w-16 rounded object-cover"
                                                />
                                            ) : (
                                                <span className="flex h-16 w-16 items-center justify-center text-gray-500">
                                                    <FileText className="h-7 w-7" />
                                                </span>
                                            )}
                                        </button>
                                    ) : null}
                                    <div className="min-w-0 flex-1">
                                        <p className="text-foreground truncate font-medium">{p.original_name}</p>
                                        <p className="text-xs text-gray-500">
                                            Enviado: {new Date(p.created_at).toLocaleString('es-CO', { timeZone: 'America/Bogota' })}
                                        </p>
                                        {previewUrl && (
                                            <button
                                                type="button"
                                                onClick={openPreview}
                                                className="text-primary mt-1 inline-block text-xs font-medium underline underline-offset-2 hover:opacity-80"
                                            >
                                                {previewLabel}
                                            </button>
                                        )}
                                        {p.review_notes && <p className="mt-1 text-xs text-gray-600 italic">{p.review_notes}</p>}
                                    </div>
                                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_CLASS[p.status]}`}>
                                        {STATUS_LABEL[p.status]}
                                    </span>
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>
            )}

            {/* Popup de previsualización (#193). Se monta cuando `previewing`
                no es null. Para imágenes muestra el `<img>` a tamaño contenido;
                para PDFs embebe el archivo en un `<iframe>` (los browsers
                modernos vienen con visor PDF nativo). Cualquier otro mime cae
                al fallback "Descargar" con link directo. */}
            <Dialog open={previewing !== null} onOpenChange={(open) => !open && setPreviewing(null)}>
                <DialogContent className="max-w-4xl p-0 sm:rounded-xl">
                    {previewing && (
                        <div className="flex flex-col">
                            <div className="border-border bg-card flex items-start justify-between gap-3 border-b px-4 py-3 pr-12">
                                <div className="min-w-0">
                                    <DialogTitle className="text-foreground truncate text-sm font-semibold">{previewing.original_name}</DialogTitle>
                                    <p className="text-muted-foreground mt-0.5 text-xs">
                                        Enviado: {new Date(previewing.created_at).toLocaleString('es-CO', { timeZone: 'America/Bogota' })}
                                    </p>
                                </div>
                            </div>
                            <div className="bg-muted/40 flex max-h-[80vh] items-center justify-center overflow-auto p-2">
                                {(() => {
                                    const isImg = typeof previewing.mime === 'string' && previewing.mime.startsWith('image/');
                                    const isPdfPreview = previewing.mime === 'application/pdf';
                                    if (!previewing.preview_url) {
                                        return <p className="text-muted-foreground text-sm">Este comprobante no tiene preview disponible.</p>;
                                    }
                                    if (isImg) {
                                        return (
                                            <img
                                                src={previewing.preview_url}
                                                alt={previewing.original_name}
                                                className="max-h-[78vh] max-w-full object-contain"
                                            />
                                        );
                                    }
                                    if (isPdfPreview) {
                                        return (
                                            <iframe
                                                src={previewing.preview_url}
                                                title={previewing.original_name}
                                                className="h-[78vh] w-full border-0"
                                            />
                                        );
                                    }
                                    return (
                                        <a
                                            href={previewing.preview_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary text-sm font-medium underline underline-offset-2"
                                        >
                                            Descargar {previewing.original_name}
                                        </a>
                                    );
                                })()}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
