import { useEffect, useMemo, useState } from 'react';

import { PageShell } from '@/components/page-shell';
import { DocumentStatusBadge } from '@/components/dian/document-status-badge';
import { DocumentTypeBadge } from '@/components/dian/document-type-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { getDocumentPdfUrl, listDocuments, retryDocument } from '@/lib/dian-api';
import {
    type DianDocumentStatus,
    type DianDocumentType,
    DIAN_DOC_TYPE_LABELS,
    DIAN_STATUS_LABELS,
    type DianElectronicDocument,
} from '@/types/dian';

/**
 * Listado de documentos electrónicos DIAN — vista operativa.
 *
 * Responsive: tabla desktop (md+) y cards apiladas en mobile (<md).
 * Filtros: status, tipo de documento, fecha desde/hasta + búsqueda libre
 * sobre full_number (client-side por simplicidad — backend devuelve hasta
 * 25 por página).
 */
export default function DianDocumentsPage() {
    const [docs, setDocs] = useState<DianElectronicDocument[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<string>('all');
    const [type, setType] = useState<string>('all');
    const [from, setFrom] = useState<string>('');
    const [to, setTo] = useState<string>('');

    const fetchDocs = () => {
        setLoading(true);
        listDocuments({
            status: status === 'all' ? undefined : status,
            document_type: type === 'all' ? undefined : type,
            from: from || undefined,
            to: to || undefined,
            per_page: 50,
        })
            .then(({ data }) => setDocs(data ?? []))
            .catch((err) => setError(err instanceof Error ? err.message : 'Error cargando documentos'))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        fetchDocs();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [status, type, from, to]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return docs;
        return docs.filter(
            (d) =>
                d.full_number.toLowerCase().includes(q) ||
                d.unique_code.toLowerCase().includes(q) ||
                (d.provider_track_id ?? '').toLowerCase().includes(q),
        );
    }, [docs, search]);

    return (
        <PageShell title="Documentos DIAN">
            <div className="flex flex-col gap-6">
                <PageHeader
                    eyebrow="FACTURACIÓN ELECTRÓNICA"
                    title="Documentos DIAN"
                    description="DEE POS y facturas electrónicas emitidas desde esta sede."
                    variant="dense"
                />

                <FilterBar
                    searchValue={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Buscar por número, CUFE/CUDE o track ID..."
                    variant="card"
                >
                    <Select value={status} onValueChange={setStatus}>
                        <SelectTrigger className="w-full sm:w-auto sm:min-w-[170px]">
                            <SelectValue placeholder="Estado" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos los estados</SelectItem>
                            {(Object.keys(DIAN_STATUS_LABELS) as DianDocumentStatus[]).map((s) => (
                                <SelectItem key={s} value={s}>
                                    {DIAN_STATUS_LABELS[s]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={type} onValueChange={setType}>
                        <SelectTrigger className="w-full sm:w-auto sm:min-w-[170px]">
                            <SelectValue placeholder="Tipo" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos los tipos</SelectItem>
                            {(Object.keys(DIAN_DOC_TYPE_LABELS) as DianDocumentType[]).map((t) => (
                                <SelectItem key={t} value={t}>
                                    {DIAN_DOC_TYPE_LABELS[t]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-full sm:w-auto" />
                    <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-full sm:w-auto" />
                </FilterBar>

                {error && (
                    <div className="rounded-md border border-[color:var(--color-status-critical)]/40 bg-[color:var(--color-status-critical)]/5 p-3 text-sm text-[color:var(--color-status-critical)]">
                        {error}
                    </div>
                )}

                {loading ? (
                    <div className="space-y-2">
                        {Array.from({ length: 5 }).map((_, i) => (
                            <Skeleton key={i} className="h-14 w-full" />
                        ))}
                    </div>
                ) : filtered.length === 0 ? (
                    <EmptyState
                        title="Sin documentos"
                        description="No hay documentos DIAN emitidos con esos filtros."
                    />
                ) : (
                    <>
                        {/* Desktop table */}
                        <div className="hidden md:block">
                            <Card className="overflow-hidden">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 text-left">Número</th>
                                            <th className="px-3 py-2 text-left">Tipo</th>
                                            <th className="px-3 py-2 text-left">Estado</th>
                                            <th className="px-3 py-2 text-left">Fecha emisión</th>
                                            <th className="px-3 py-2 text-left">Orden</th>
                                            <th className="px-3 py-2 text-left">Track</th>
                                            <th className="px-3 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filtered.map((doc) => (
                                            <DocumentTableRow key={doc.id} doc={doc} onRetry={fetchDocs} />
                                        ))}
                                    </tbody>
                                </table>
                            </Card>
                        </div>

                        {/* Mobile cards */}
                        <div className="space-y-2 md:hidden">
                            {filtered.map((doc) => (
                                <DocumentCard key={doc.id} doc={doc} onRetry={fetchDocs} />
                            ))}
                        </div>
                    </>
                )}
            </div>
        </PageShell>
    );
}

function DocumentTableRow({ doc, onRetry }: { doc: DianElectronicDocument; onRetry: () => void }) {
    return (
        <tr className="border-t hover:bg-muted/40">
            <td className="px-3 py-2 font-mono text-xs">{doc.full_number}</td>
            <td className="px-3 py-2">
                <DocumentTypeBadge type={doc.document_type} />
            </td>
            <td className="px-3 py-2">
                <DocumentStatusBadge status={doc.status} />
                {doc.rejection_reason && (
                    <div className="mt-1 text-xs text-muted-foreground line-clamp-1">{doc.rejection_reason}</div>
                )}
            </td>
            <td className="px-3 py-2 text-muted-foreground">
                {doc.issued_at ? new Date(doc.issued_at).toLocaleString('es-CO') : '—'}
            </td>
            <td className="px-3 py-2 text-muted-foreground">#{doc.order_id ?? '—'}</td>
            <td className="px-3 py-2 text-muted-foreground font-mono text-xs">
                {doc.provider_track_id ?? '—'}
            </td>
            <td className="px-3 py-2 text-right">
                <DocumentActions doc={doc} onRetry={onRetry} />
            </td>
        </tr>
    );
}

function DocumentCard({ doc, onRetry }: { doc: DianElectronicDocument; onRetry: () => void }) {
    return (
        <Card className="p-3 space-y-2">
            <div className="flex items-start justify-between gap-2">
                <div className="space-y-1">
                    <div className="font-mono text-sm">{doc.full_number}</div>
                    <DocumentTypeBadge type={doc.document_type} />
                </div>
                <DocumentStatusBadge status={doc.status} />
            </div>
            {doc.rejection_reason && (
                <div className="text-xs text-[color:var(--color-status-critical)]">{doc.rejection_reason}</div>
            )}
            <div className="text-xs text-muted-foreground">
                {doc.issued_at ? new Date(doc.issued_at).toLocaleString('es-CO') : '—'} · orden #{doc.order_id ?? '—'}
            </div>
            <div className="pt-1">
                <DocumentActions doc={doc} onRetry={onRetry} />
            </div>
        </Card>
    );
}

function DocumentActions({ doc, onRetry }: { doc: DianElectronicDocument; onRetry: () => void }) {
    const [busy, setBusy] = useState(false);

    const handlePdf = async () => {
        try {
            const { url } = await getDocumentPdfUrl(doc.id);
            window.open(url, '_blank', 'noopener,noreferrer');
        } catch (e) {
            console.error(e);
        }
    };
    const handleRetry = async () => {
        setBusy(true);
        try {
            await retryDocument(doc.id);
            onRetry();
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="flex flex-wrap gap-1 justify-end">
            {doc.status === 'accepted' && (
                <Button variant="outline" size="sm" onClick={handlePdf}>
                    PDF
                </Button>
            )}
            {(doc.status === 'rejected' || doc.status === 'error') && (
                <Button variant="outline" size="sm" disabled={busy} onClick={handleRetry}>
                    Reintentar
                </Button>
            )}
        </div>
    );
}
