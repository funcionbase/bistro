import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, FileText } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';

import { DocumentStatusBadge } from '@/components/dian/document-status-badge';
import { DocumentTypeBadge } from '@/components/dian/document-type-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useBootstrap } from '@/hooks/use-bootstrap';
import { getDocumentPdfUrl, getDocumentXmlUrl, listDocuments } from '@/lib/dian-api';
import { formatDateTimeShort } from '@/lib/datetime';
import { DIAN_DOC_TYPE_LABELS, DIAN_STATUS_LABELS, type DianElectronicDocument, type DianResolution } from '@/types/dian';

interface DocumentsExplorerProps {
    /** Catálogo de resoluciones de la empresa (lo carga la página). */
    resolutions: DianResolution[];
    /** Resolución seleccionada (controlada por la página para el salto desde el tab Resoluciones). */
    resolutionId: string;
    onResolutionChange: (id: string) => void;
}

type SortColumn = 'issued_at' | 'full_number' | 'consecutive' | 'status' | 'document_type';

interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

/**
 * Consulta de facturas electrónicas emitidas — tab "Facturas" de /company/dian.
 *
 * Flujo: seleccionar resolución (obligatorio) → sede o toda la empresa →
 * tabla paginada server-side con búsqueda (número/CUFE/track) y ordenamiento
 * por columna. El detalle (dialog) muestra el documento completo, la
 * resolución a la que quedó ligado (sumó a su conteo) y el enlace a la orden.
 */
export function DocumentsExplorer({ resolutions, resolutionId, onResolutionChange }: DocumentsExplorerProps) {
    const bootstrapBranches = useBootstrap().data?.branches;
    const branches = useMemo(() => bootstrapBranches ?? [], [bootstrapBranches]);

    const [branch, setBranch] = useState<string>('all');
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [sort, setSort] = useState<SortColumn>('issued_at');
    const [dir, setDir] = useState<'asc' | 'desc'>('desc');
    const [page, setPage] = useState(1);
    const [docs, setDocs] = useState<DianElectronicDocument[]>([]);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [detail, setDetail] = useState<DianElectronicDocument | null>(null);

    const branchNames = useMemo(() => new Map(branches.map((b) => [b.id, b.name])), [branches]);
    const resolution = resolutions.find((r) => r.id === resolutionId) ?? null;

    // Debounce del buscador — evita un request por tecla.
    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(search.trim()), 400);
        return () => clearTimeout(t);
    }, [search]);

    // Cambiar cualquier filtro reinicia a la página 1.
    useEffect(() => {
        setPage(1);
    }, [resolutionId, branch, debouncedSearch, sort, dir]);

    useEffect(() => {
        if (!resolutionId) {
            setDocs([]);
            setMeta(null);
            return;
        }
        setLoading(true);
        setError(null);
        listDocuments({
            resolution_id: resolutionId,
            branch,
            q: debouncedSearch || undefined,
            sort,
            dir,
            page,
            per_page: 25,
        })
            .then((res) => {
                setDocs(res.data ?? []);
                setMeta(res.meta ?? null);
            })
            .catch((err) => setError(err instanceof Error ? err.message : 'Error cargando facturas'))
            .finally(() => setLoading(false));
    }, [resolutionId, branch, debouncedSearch, sort, dir, page]);

    const toggleSort = (column: SortColumn) => {
        if (sort === column) {
            setDir(dir === 'asc' ? 'desc' : 'asc');
        } else {
            setSort(column);
            setDir(column === 'issued_at' ? 'desc' : 'asc');
        }
    };

    return (
        <div className="space-y-4">
            {/* Paso 1: resolución · Paso 2: sede o toda la empresa. */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <p className="text-muted-foreground mb-1 text-[11px] uppercase tracking-[0.15em]">Resolución</p>
                    <Select value={resolutionId || undefined} onValueChange={onResolutionChange}>
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Seleccionar resolución..." />
                        </SelectTrigger>
                        <SelectContent>
                            {resolutions.map((r) => (
                                <SelectItem key={r.id} value={r.id}>
                                    {r.prefix} · {DIAN_DOC_TYPE_LABELS[r.document_type] ?? r.document_type} · Res. {r.resolution_number}
                                    {!r.is_active && ' (histórica)'}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div>
                    <p className="text-muted-foreground mb-1 text-[11px] uppercase tracking-[0.15em]">Alcance</p>
                    <Select value={branch} onValueChange={setBranch}>
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Toda la empresa</SelectItem>
                            {branches.map((b) => (
                                <SelectItem key={b.id} value={b.id}>
                                    Sede: {b.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            {/* Consumo de la resolución seleccionada — las facturas listadas sumaron a este conteo. */}
            {resolution && (
                <p className="text-muted-foreground text-xs">
                    Resolución {resolution.resolution_number} · rango {resolution.prefix}
                    {resolution.range_from}–{resolution.prefix}
                    {resolution.range_to} · consumido <strong className="text-foreground">{resolution.current_number}</strong> de{' '}
                    {resolution.range_to}
                </p>
            )}

            {!resolutionId ? (
                <EmptyState
                    title="Seleccioná una resolución"
                    description="Elegí la resolución DIAN que querés consultar para ver las facturas que se emitieron con ella."
                />
            ) : (
                <>
                    <FilterBar
                        searchValue={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Buscar por número, CUFE/CUDE o track ID..."
                        variant="card"
                    />

                    {error && (
                        <div className="rounded-md border border-[color:var(--color-status-critical)]/40 bg-[color:var(--color-status-critical)]/5 p-3 text-sm text-[color:var(--color-status-critical)]">
                            {error}
                        </div>
                    )}

                    {loading ? (
                        <div className="space-y-2">
                            {Array.from({ length: 5 }).map((_, i) => (
                                <Skeleton key={i} className="h-12 w-full" />
                            ))}
                        </div>
                    ) : docs.length === 0 ? (
                        <EmptyState title="Sin facturas" description="No hay documentos emitidos con esa resolución y esos filtros." />
                    ) : (
                        <>
                            {/* Desktop: tabla con ordenamiento por columna. */}
                            <div className="hidden sm:block">
                                <Card className="overflow-x-auto p-0">
                                    <table className="w-full text-sm">
                                        <thead className="bg-muted text-muted-foreground">
                                            <tr>
                                                <SortableHeader label="Número" column="full_number" sort={sort} dir={dir} onSort={toggleSort} />
                                                <SortableHeader label="Tipo" column="document_type" sort={sort} dir={dir} onSort={toggleSort} />
                                                <SortableHeader label="Estado" column="status" sort={sort} dir={dir} onSort={toggleSort} />
                                                <SortableHeader label="Fecha emisión" column="issued_at" sort={sort} dir={dir} onSort={toggleSort} />
                                                <th className="px-3 py-2 text-left font-medium">Sede</th>
                                                <th className="px-3 py-2 text-left font-medium">Orden</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {docs.map((doc) => (
                                                <tr
                                                    key={doc.id}
                                                    className="hover:bg-muted/40 cursor-pointer border-t"
                                                    onClick={() => setDetail(doc)}
                                                >
                                                    <td className="px-3 py-2 font-mono text-xs">{doc.full_number}</td>
                                                    <td className="px-3 py-2">
                                                        <DocumentTypeBadge type={doc.document_type} />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <DocumentStatusBadge status={doc.status} />
                                                    </td>
                                                    <td className="text-muted-foreground px-3 py-2">
                                                        {doc.issued_at ? formatDateTimeShort(doc.issued_at) : '—'}
                                                    </td>
                                                    <td className="text-muted-foreground px-3 py-2">{branchNames.get(doc.branch_id) ?? '—'}</td>
                                                    <td className="text-muted-foreground px-3 py-2">
                                                        {doc.order_id !== null ? (
                                                            <Link
                                                                to={`/orders/${doc.order_id}`}
                                                                className="text-primary hover:underline"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                Ver orden
                                                            </Link>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </Card>
                            </div>

                            {/* Mobile: cards apiladas. */}
                            <div className="space-y-2 sm:hidden">
                                {docs.map((doc) => (
                                    <Card key={doc.id} className="cursor-pointer space-y-2 p-3" onClick={() => setDetail(doc)}>
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="space-y-1">
                                                <div className="font-mono text-sm">{doc.full_number}</div>
                                                <DocumentTypeBadge type={doc.document_type} />
                                            </div>
                                            <DocumentStatusBadge status={doc.status} />
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {doc.issued_at ? formatDateTimeShort(doc.issued_at) : '—'} ·{' '}
                                            {branchNames.get(doc.branch_id) ?? 'sede desconocida'}
                                        </div>
                                    </Card>
                                ))}
                            </div>

                            {/* Paginación server-side. */}
                            {meta && meta.last_page > 1 && (
                                <div className="flex items-center justify-between gap-3">
                                    <p className="text-muted-foreground text-xs">
                                        Página {meta.current_page} de {meta.last_page} · {meta.total} documento{meta.total === 1 ? '' : 's'}
                                    </p>
                                    <div className="flex gap-1">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={page <= 1 || loading}
                                            onClick={() => setPage((p) => p - 1)}
                                            aria-label="Página anterior"
                                        >
                                            <ChevronLeft className="h-4 w-4" />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={page >= meta.last_page || loading}
                                            onClick={() => setPage((p) => p + 1)}
                                            aria-label="Página siguiente"
                                        >
                                            <ChevronRight className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </>
            )}

            <DocumentDetailDialog doc={detail} resolution={resolution} branchName={detail ? (branchNames.get(detail.branch_id) ?? null) : null} onClose={() => setDetail(null)} />
        </div>
    );
}

function SortableHeader({
    label,
    column,
    sort,
    dir,
    onSort,
}: {
    label: string;
    column: SortColumn;
    sort: SortColumn;
    dir: 'asc' | 'desc';
    onSort: (column: SortColumn) => void;
}) {
    const active = sort === column;
    const Icon = active ? (dir === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown;
    return (
        <th className="px-3 py-2 text-left font-medium">
            <button
                type="button"
                onClick={() => onSort(column)}
                className={`inline-flex items-center gap-1 ${active ? 'text-foreground' : ''} hover:text-foreground transition-colors`}
            >
                {label}
                <Icon className="h-3 w-3" />
            </button>
        </th>
    );
}

/** Detalle del documento: campos completos + resolución ligada + enlace a la orden. */
function DocumentDetailDialog({
    doc,
    resolution,
    branchName,
    onClose,
}: {
    doc: DianElectronicDocument | null;
    resolution: DianResolution | null;
    branchName: string | null;
    onClose: () => void;
}) {
    const [blobBusy, setBlobBusy] = useState(false);

    const openBlob = async (kind: 'pdf' | 'xml') => {
        if (!doc) return;
        setBlobBusy(true);
        try {
            const { url } = kind === 'pdf' ? await getDocumentPdfUrl(doc.id) : await getDocumentXmlUrl(doc.id);
            window.open(url, '_blank', 'noopener,noreferrer');
        } catch {
            // URL firmada no disponible (blob ausente) — el botón solo aparece con has_pdf/has_xml.
        } finally {
            setBlobBusy(false);
        }
    };

    return (
        <Dialog open={doc !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                {doc && (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex flex-wrap items-center gap-2 font-mono">
                                <FileText className="h-4 w-4" /> {doc.full_number}
                            </DialogTitle>
                            <DialogDescription>
                                {DIAN_DOC_TYPE_LABELS[doc.document_type] ?? doc.document_type} · {DIAN_STATUS_LABELS[doc.status] ?? doc.status}
                            </DialogDescription>
                        </DialogHeader>

                        <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                            <DetailField label="Estado">
                                <DocumentStatusBadge status={doc.status} />
                                {doc.rejection_reason && (
                                    <p className="mt-1 text-xs text-[color:var(--color-status-critical)]">{doc.rejection_reason}</p>
                                )}
                            </DetailField>
                            <DetailField label="Sede">{branchName ?? '—'}</DetailField>
                            <DetailField label="Fecha emisión">{doc.issued_at ? formatDateTimeShort(doc.issued_at) : '—'}</DetailField>
                            <DetailField label="Aceptado">{doc.accepted_at ? formatDateTimeShort(doc.accepted_at) : '—'}</DetailField>
                            <DetailField label={doc.unique_code_type === 'cude' ? 'CUDE' : 'CUFE'}>
                                <span className="break-all font-mono text-xs">{doc.unique_code}</span>
                            </DetailField>
                            <DetailField label="Ambiente">{doc.environment ?? '—'}</DetailField>
                        </dl>

                        {/* Resolución a la que quedó ligado el documento (sumó a su conteo). */}
                        <div className="bg-muted/50 rounded-xl p-3 text-sm">
                            <p className="text-muted-foreground mb-1 text-[11px] uppercase tracking-[0.15em]">Resolución ligada</p>
                            {resolution && resolution.id === doc.dian_resolution_id ? (
                                <>
                                    <p className="font-medium">
                                        Resolución {resolution.resolution_number}{' '}
                                        <Badge variant="secondary" className="ml-1 font-mono text-[10px] uppercase">
                                            {resolution.prefix}
                                        </Badge>
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        Rango {resolution.prefix}
                                        {resolution.range_from}–{resolution.prefix}
                                        {resolution.range_to} · este documento consumió el consecutivo{' '}
                                        <strong className="text-foreground">{doc.consecutive}</strong>
                                    </p>
                                </>
                            ) : (
                                <p className="text-muted-foreground font-mono text-xs">{doc.dian_resolution_id}</p>
                            )}
                        </div>

                        <div className="flex flex-wrap justify-end gap-2 pt-1">
                            {doc.has_pdf && (
                                <Button variant="outline" size="sm" disabled={blobBusy} onClick={() => void openBlob('pdf')}>
                                    PDF
                                </Button>
                            )}
                            {doc.has_xml && (
                                <Button variant="outline" size="sm" disabled={blobBusy} onClick={() => void openBlob('xml')}>
                                    XML
                                </Button>
                            )}
                            {doc.order_id !== null && (
                                <Button asChild size="sm">
                                    <Link to={`/orders/${doc.order_id}`}>Ver orden</Link>
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function DetailField({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">{label}</dt>
            <dd className="mt-1">{children}</dd>
        </div>
    );
}
