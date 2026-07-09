import { useCallback, useEffect, useState } from 'react';

import { DocumentStatusBadge } from '@/components/dian/document-status-badge';
import { DocumentTypeBadge } from '@/components/dian/document-type-badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { usePermissions } from '@/hooks/use-permissions';
import { useHasPlanFeature } from '@/hooks/use-plan-feature';
import {
    convertToFev,
    DianApiError,
    emitCreditNote,
    emitDocument,
    getDocumentPdfUrl,
    listDocuments,
    printDocument,
    retryDocument,
} from '@/lib/dian-api';
import type { DianElectronicDocument } from '@/types/dian';

/**
 * Acciones DIAN inline en el detalle de una orden.
 *
 * Decide qué mostrar según el estado del documento DIAN ligado a la orden:
 *  - Sin documento → "Emitir documento DIAN" (gate por `dian.documents.emit`).
 *  - `accepted` → badge tipo + estado; acciones: ver PDF, reimprimir,
 *    convertir a FEV (si es DEE POS), emitir nota crédito.
 *  - `pending`/`queued`/`sent` → badge "procesando", sin acciones.
 *  - `rejected`/`error` → badge crítico, acción "Reintentar".
 *  - `needs_recipient_data` → badge warning, info para que el cajero capture
 *    datos del adquirente desde el cobro POS.
 *
 * Carga documentos vía `listDocuments({ order_id })` — silencioso si no hay.
 */
interface Props {
    orderId: string;
    orderStatus: string;
    /** Default: pos_equivalent (DEE POS al consumidor final). */
    defaultDocumentType?: 'pos_equivalent' | 'invoice';
    /** Callback opcional para refrescar el detalle de la orden tras una emisión. */
    onChange?: () => void;
}

export function DianOrderActions({ orderId, orderStatus, defaultDocumentType = 'pos_equivalent', onChange }: Props) {
    const { has } = usePermissions();
    const hasDianFeature = useHasPlanFeature('dian');
    const [documents, setDocuments] = useState<DianElectronicDocument[]>([]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const canRead = has('dian.documents.read');
    const canEmit = has('dian.documents.emit');
    const canRetry = has('dian.documents.retry');
    const canCreditNote = has('dian.documents.credit_note');
    const canPrint = has('dian.print');

    const fetchDocs = useCallback(() => {
        setLoading(true);
        listDocuments({ order_id: orderId })
            .then(({ data }) => setDocuments(data ?? []))
            .catch(() => setDocuments([]))
            .finally(() => setLoading(false));
    }, [orderId]);

    useEffect(() => {
        // Sin Plan Plus, ni documentos hay que traer — evita el fetch (el
        // backend igual lo rechazaría con 403 plan.feature_not_included).
        if (canRead && hasDianFeature) {
            fetchDocs();
        } else {
            setLoading(false);
        }
    }, [canRead, hasDianFeature, fetchDocs]);

    if (!canRead) {
        return null;
    }

    if (!hasDianFeature) {
        return <p className="text-muted-foreground text-xs italic">Facturación DIAN: opción no incluida en tu plan actual.</p>;
    }

    if (loading) {
        return <Skeleton className="h-8 w-40" />;
    }

    // Última emisión activa (no nota crédito) para decidir el contexto.
    const active = documents
        .filter((d) => d.document_type === 'pos_equivalent' || d.document_type === 'invoice')
        .sort((a, b) => (b.id ?? '').localeCompare(a.id ?? ''))[0];

    // ¿Ya hay nota crédito viva apuntando al documento activo? Guard de
    // idempotencia visual — refleja la regla backend que bloquea duplicados.
    const existingCreditNote = active
        ? documents.find(
              (d) =>
                  (d.document_type === 'credit_note' || d.document_type === 'pos_equivalent_credit_note') &&
                  d.references_document_id === active.id &&
                  d.status !== 'rejected' &&
                  d.status !== 'error',
          )
        : undefined;

    const orderIsEmittable = orderStatus === 'completed';

    const handleEmit = async () => {
        setBusy(true);
        setError(null);
        try {
            await emitDocument({ order_id: orderId, document_type: defaultDocumentType });
            fetchDocs();
            onChange?.();
        } catch (e) {
            setError(extractError(e));
        } finally {
            setBusy(false);
        }
    };

    const handleRetry = async () => {
        if (!active) return;
        setBusy(true);
        setError(null);
        try {
            await retryDocument(active.id);
            fetchDocs();
        } catch (e) {
            setError(extractError(e));
        } finally {
            setBusy(false);
        }
    };

    const handleCreditNote = async () => {
        if (!active) return;
        setBusy(true);
        setError(null);
        try {
            await emitCreditNote(active.id);
            fetchDocs();
            onChange?.();
        } catch (e) {
            setError(extractError(e));
        } finally {
            setBusy(false);
        }
    };

    const handleConvertToFev = async () => {
        if (!active || active.document_type !== 'pos_equivalent') return;
        setBusy(true);
        setError(null);
        try {
            await convertToFev(active.id);
            fetchDocs();
            onChange?.();
        } catch (e) {
            setError(extractError(e));
        } finally {
            setBusy(false);
        }
    };

    const handlePrint = async () => {
        if (!active) return;
        setBusy(true);
        setError(null);
        try {
            await printDocument(active.id);
        } catch (e) {
            setError(extractError(e));
        } finally {
            setBusy(false);
        }
    };

    const handlePdf = async () => {
        if (!active) return;
        try {
            const { url } = await getDocumentPdfUrl(active.id);
            window.open(url, '_blank', 'noopener,noreferrer');
        } catch (e) {
            setError(extractError(e));
        }
    };

    // Sin documento previo
    if (!active) {
        return (
            <div className="flex flex-col gap-1">
                {canEmit && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={handleEmit}
                        disabled={busy || !orderIsEmittable}
                        title={!orderIsEmittable ? 'La orden debe estar en estado completed' : undefined}
                    >
                        {busy ? 'Emitiendo…' : 'Emitir documento DIAN'}
                    </Button>
                )}
                {error && <p className="text-xs text-[color:var(--color-status-critical)]">{error}</p>}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-1">
            <div className="flex flex-wrap items-center gap-2">
                <DocumentTypeBadge type={active.document_type} />
                <DocumentStatusBadge status={active.status} />
                <span className="text-muted-foreground text-xs font-mono">{active.full_number}</span>
            </div>

            <TooltipProvider delayDuration={300}>
                <div className="flex flex-wrap gap-1">
                    {active.status === 'accepted' && (
                        <>
                            {active.has_pdf ? (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button type="button" variant="outline" size="sm" onClick={handlePdf} disabled={busy}>
                                            PDF
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Abre el PDF de la representación gráfica del documento DIAN en otra pestaña (URL firmada 15 min).</TooltipContent>
                                </Tooltip>
                            ) : (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span className="text-muted-foreground text-xs italic">PDF no disponible</span>
                                    </TooltipTrigger>
                                    <TooltipContent>Documento sembrado en demo — el blob no se subió a S3. Para verlo, reemití la orden y se regenera el PDF.</TooltipContent>
                                </Tooltip>
                            )}
                            {canPrint && active.has_pdf && (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button type="button" variant="outline" size="sm" onClick={handlePrint} disabled={busy}>
                                            {busy ? 'Imprimiendo…' : 'Reimprimir tirilla'}
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Envía la tirilla a la impresora térmica del cliente (ticket compacto con QR del CUFE/CUDE).</TooltipContent>
                                </Tooltip>
                            )}
                            {canCreditNote &&
                                (existingCreditNote ? (
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <span className="text-muted-foreground border-border bg-muted/40 inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs">
                                                Nota crédito emitida:{' '}
                                                <span className="text-foreground font-mono font-medium">{existingCreditNote.full_number}</span>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Ya existe una NC ({existingCreditNote.full_number}) que anula este documento. No se permite duplicar
                                            mientras esté viva (status: {existingCreditNote.status}).
                                        </TooltipContent>
                                    </Tooltip>
                                ) : (
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <Button type="button" variant="outline" size="sm" onClick={handleCreditNote} disabled={busy}>
                                                Emitir nota crédito
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>Anula este documento. Crea un asiento nuevo (el original queda inmutable por ley DIAN). Solo una NC viva por documento.</TooltipContent>
                                    </Tooltip>
                                ))}
                            {active.document_type === 'pos_equivalent' && canEmit && (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button type="button" variant="outline" size="sm" onClick={handleConvertToFev} disabled={busy}>
                                            Convertir a FEV
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Emite una Factura Electrónica de Venta nueva ligada al cliente identificado, referenciando este DEE POS. Útil cuando el cliente pide factura formal después del cobro.</TooltipContent>
                                </Tooltip>
                            )}
                        </>
                    )}
                    {(active.status === 'rejected' || active.status === 'error') && canRetry && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button type="button" variant="outline" size="sm" onClick={handleRetry} disabled={busy}>
                                    Reintentar
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>Vuelve a enviar el documento al proveedor DIAN sin generar nueva numeración. Para usar cuando el rechazo fue por problema técnico transitorio.</TooltipContent>
                        </Tooltip>
                    )}
                    {active.status === 'needs_recipient_data' && (
                        <span className="text-xs text-[color:var(--color-status-warning)]">
                            Captura los datos del contacto desde "Cerrar y cobrar" → reintenta la emisión.
                        </span>
                    )}
                </div>
            </TooltipProvider>

            {active.rejection_reason && (
                <p className="text-xs text-[color:var(--color-status-critical)]">{active.rejection_reason}</p>
            )}
            {error && <p className="text-xs text-[color:var(--color-status-critical)]">{error}</p>}
        </div>
    );
}

/**
 * Mensajes amigables por código de error DIAN del backend. La clave es el slug
 * que viaja en `error`/`code`; el valor es texto orientado al cajero/owner sin
 * jerga técnica (nada de `empresa=11123`, `document_type=pos_equivalent`, etc.).
 */
const FRIENDLY_DIAN_ERRORS: Record<string, string> = {
    'dian.resolution_unavailable':
        'No hay una resolución DIAN activa y vigente para emitir este documento. Pedile al administrador que registre una en Configuración → Facturación DIAN → Resoluciones.',
    'dian.order_not_emittable': 'Esta orden no se puede facturar todavía. Debe estar completada y cobrada antes de emitir el documento DIAN.',
    'dian.retry_failed': 'No se pudo reenviar el documento a la DIAN. Esperá un momento y volvé a intentar.',
    'plan.feature_not_included': 'Esta opción no está incluida en tu plan actual.',
    DIAN_CREDIT_NOTE_ALREADY_EXISTS: 'Este documento ya tiene una nota crédito emitida. No se puede duplicar.',
    DIAN_BLOB_NOT_AVAILABLE: 'El archivo del documento no está disponible. Reemití la orden para regenerarlo.',
};

function extractError(e: unknown): string {
    if (e instanceof DianApiError) {
        if (e.code && FRIENDLY_DIAN_ERRORS[e.code]) {
            return FRIENDLY_DIAN_ERRORS[e.code];
        }
        // Sin código mapeado: el `message` del backend ya viene en español y limpio.
        return e.message || 'Ocurrió un error procesando la acción DIAN.';
    }
    if (e instanceof Error) {
        return e.message;
    }
    return 'Ocurrió un error procesando la acción DIAN.';
}
