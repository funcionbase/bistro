import { AppLink } from '@/components/app-link';
import { NewClientDialog } from '@/components/clients/new-client-dialog';
import { SegmentBadge, segmentLabel } from '@/components/clients/segment-badge';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ClientsListSkeleton } from '@/components/ui/clients-list-skeleton';
import { DataCard, DataCardList } from '@/components/ui/data-card-list';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { PageHeader } from '@/components/ui/page-header';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useClients, type ClientListFilters, type ClientSegment } from '@/hooks/use-clients';
import { usePermissions } from '@/hooks/use-permissions';
import { useToken } from '@/hooks/use-token';
import { formatCurrency } from '@/lib/coupon-helpers';

import { AlertCircle, Building2, ChevronLeft, ChevronRight, RefreshCw, User, UserPlus, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';


function formatRelative(iso: string | null): string {
    if (!iso) return '—';
    const diffMs = Date.now() - new Date(iso).getTime();
    const diffDays = Math.floor(diffMs / 86_400_000);
    if (diffDays === 0) return 'hoy';
    if (diffDays === 1) return 'ayer';
    if (diffDays < 30) return `hace ${diffDays}d`;
    if (diffDays < 365) return `hace ${Math.floor(diffDays / 30)}m`;
    return `hace ${Math.floor(diffDays / 365)}a`;
}

function formatPhone(phone: string | null): string {
    if (!phone) return '—';
    // Display friendly: 57XXXXXXXXXX → +57 3XX XXX XXXX. En BD siempre 57XXXXXXXXXX.
    if (phone.startsWith('57') && phone.length === 12) {
        return `+57 ${phone.slice(2, 5)} ${phone.slice(5, 8)} ${phone.slice(8)}`;
    }
    return phone;
}

export default function ClientsIndex() {
    const navigate = useNavigate();
    const token = useToken();
    const { has } = usePermissions();
    const canCreate = has('clients.create');
    const [searchInput, setSearchInput] = useState('');
    const [filters, setFilters] = useState<ClientListFilters>({ page: 1, per_page: 25 });
    const [newClientOpen, setNewClientOpen] = useState(false);

    // Debounce de búsqueda para no disparar requests por cada tecla.
    useEffect(() => {
        const handle = setTimeout(() => {
            setFilters((f) => ({ ...f, search: searchInput || undefined, page: 1 }));
        }, 300);
        return () => clearTimeout(handle);
    }, [searchInput]);

    const { clients, meta, loading, error, refresh } = useClients(token, filters);

    const segments: ClientSegment[] = useMemo(() => meta?.segments ?? [], [meta]);
    const availableTags = useMemo(() => meta?.available_tags ?? [], [meta]);

    function setSegment(seg: ClientSegment | '') {
        setFilters((f) => ({ ...f, segment: seg, page: 1 }));
    }

    function setTag(tag: string) {
        setFilters((f) => ({ ...f, tag: tag || undefined, page: 1 }));
    }

    function goToPage(p: number) {
        setFilters((f) => ({ ...f, page: p }));
    }

    return (
        <PageShell title="Contactos">
            <div className="flex flex-col gap-6 p-4 lg:p-6">
                {loading && clients.length === 0 ? (
                    <ClientsListSkeleton rows={6} />
                ) : (
                    <>
                        <PageHeader
                            eyebrow="CRM"
                            title="Contactos"
                            description="Catálogo de contactos (personas y empresas) de la sede. Identidad canónica por número de documento; el teléfono puede compartirse entre familiares."
                            actions={
                                <>
                                    <Button variant="outline" onClick={() => void refresh()} disabled={loading}>
                                        <RefreshCw className={`mr-1 h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                                        Refrescar
                                    </Button>
                                    {canCreate && (
                                        <Button onClick={() => setNewClientOpen(true)}>
                                            <UserPlus className="mr-1 h-4 w-4" />
                                            Nuevo contacto
                                        </Button>
                                    )}
                                </>
                            }
                        />

                        {canCreate && (
                            <NewClientDialog
                                open={newClientOpen}
                                onOpenChange={setNewClientOpen}
                                onCreated={(client) => {
                                    void refresh();
                                    navigate(`/clients/${client.id}`);
                                }}
                            />
                        )}

                        <FilterBar
                            variant="card"
                            searchValue={searchInput}
                            onSearchChange={setSearchInput}
                            searchPlaceholder="Buscar por nombre, documento o teléfono..."
                            searchClassName="max-w-sm"
                        >
                            <div className="flex items-center gap-2 text-sm">
                                <span className="text-muted-foreground">Segmento:</span>
                                <button
                                    type="button"
                                    onClick={() => setSegment('')}
                                    className={`focus:ring-ring rounded px-2 py-1 text-xs transition-colors focus:ring-2 focus:outline-none ${
                                        (filters.segment ?? '') === '' ? 'bg-primary text-primary-foreground' : 'bg-muted hover:bg-secondary'
                                    }`}
                                >
                                    Todos
                                </button>
                                {segments.map((seg) => (
                                    <button
                                        key={seg}
                                        type="button"
                                        onClick={() => setSegment(seg)}
                                        className={`focus:ring-ring rounded px-2 py-1 text-xs transition-colors focus:ring-2 focus:outline-none ${
                                            filters.segment === seg ? 'bg-primary text-primary-foreground' : 'bg-muted hover:bg-secondary'
                                        }`}
                                    >
                                        {segmentLabel(seg)}
                                    </button>
                                ))}
                            </div>

                            {availableTags.length > 0 && (
                                <div className="flex items-center gap-2 text-sm">
                                    <span className="text-muted-foreground">Etiqueta:</span>
                                    <select
                                        value={filters.tag ?? ''}
                                        onChange={(e) => setTag(e.target.value)}
                                        className="border-input bg-background h-8 rounded-md border px-2 text-xs"
                                    >
                                        <option value="">Todas</option>
                                        {availableTags.map((tag) => (
                                            <option key={tag} value={tag}>
                                                #{tag}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                        </FilterBar>

                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {clients.length === 0 ? (
                            <EmptyState
                                icon={Users}
                                title="Sin contactos"
                                description="No hay contactos que coincidan con los filtros actuales. Ajustá la búsqueda o el segmento."
                            />
                        ) : (
                            <>
                                {/* Mobile: card-stack */}
                                <DataCardList
                                    items={clients}
                                    getKey={(c) => c.id}
                                    className="sm:hidden"
                                    renderCard={(client) => (
                                        <DataCard
                                            title={
                                                <AppLink href={`/clients/${client.id}`} className="text-primary hover:underline">
                                                    {client.name || 'Sin nombre'}
                                                </AppLink>
                                            }
                                            subtitle={
                                                <div className="flex flex-col text-xs">
                                                    {client.doc_number && (
                                                        <span className="text-muted-foreground">
                                                            {client.doc_type ?? 'DOC'} {client.doc_number}
                                                        </span>
                                                    )}
                                                    <span className="font-mono">{formatPhone(client.phone)}</span>
                                                </div>
                                            }
                                            fields={[
                                                {
                                                    label: 'Pedidos',
                                                    value: (
                                                        <span className="tabular-nums">
                                                            {client.total_orders}
                                                            {client.cancelled_orders > 0 && (
                                                                <span className="text-muted-foreground ml-1 text-[10px]">
                                                                    ({client.cancelled_orders} canc.)
                                                                </span>
                                                            )}
                                                        </span>
                                                    ),
                                                },
                                                {
                                                    label: 'Ticket prom.',
                                                    value: <span className="tabular-nums">{formatCurrency(client.average_ticket)}</span>,
                                                },
                                                {
                                                    label: 'Total',
                                                    value: <span className="font-medium tabular-nums">{formatCurrency(client.total_spent)}</span>,
                                                },
                                                {
                                                    label: 'Última orden',
                                                    value: formatRelative(client.last_order_at),
                                                },
                                                {
                                                    label: 'Segmento',
                                                    value: <SegmentBadge segment={client.segment} />,
                                                    full: true,
                                                },
                                                ...(client.tags.length > 0
                                                    ? [
                                                          {
                                                              label: 'Etiquetas',
                                                              value: (
                                                                  <div className="flex flex-wrap gap-1">
                                                                      {client.tags.slice(0, 3).map((t) => (
                                                                          <span key={t} className="bg-muted rounded-full px-2 py-0.5 text-[10px]">
                                                                              #{t}
                                                                          </span>
                                                                      ))}
                                                                      {client.tags.length > 3 && (
                                                                          <span className="text-muted-foreground text-[10px]">
                                                                              +{client.tags.length - 3}
                                                                          </span>
                                                                      )}
                                                                  </div>
                                                              ),
                                                              full: true,
                                                          },
                                                      ]
                                                    : []),
                                            ]}
                                        />
                                    )}
                                />

                                {/* Desktop: tabla densa */}
                                <div className="hidden sm:block">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Contacto</TableHead>
                                                <TableHead>Documento</TableHead>
                                                <TableHead>Teléfono</TableHead>
                                                <TableHead className="text-right">Pedidos</TableHead>
                                                <TableHead className="text-right">Ticket prom.</TableHead>
                                                <TableHead className="text-right">Total gastado</TableHead>
                                                <TableHead>Última orden</TableHead>
                                                <TableHead>Segmento</TableHead>
                                                <TableHead>Etiquetas</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {clients.map((client) => (
                                                <TableRow key={client.id}>
                                                    <TableCell>
                                                        <AppLink href={`/clients/${client.id}`} className="text-primary inline-flex items-center gap-2 hover:underline">
                                                            {client.kind === 'company' ? (
                                                                <Building2 className="text-muted-foreground h-3.5 w-3.5" aria-label="Empresa" />
                                                            ) : (
                                                                <User className="text-muted-foreground h-3.5 w-3.5" aria-label="Persona natural" />
                                                            )}
                                                            <span className="font-medium">{client.name || 'Sin nombre'}</span>
                                                        </AppLink>
                                                    </TableCell>
                                                    <TableCell className="text-xs">
                                                        {client.doc_number ? (
                                                            <span>
                                                                <span className="text-muted-foreground">{client.doc_type ?? 'DOC'}</span>{' '}
                                                                <span className="font-mono">{client.doc_number}</span>
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground font-mono text-xs">
                                                        {formatPhone(client.phone)}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {client.total_orders}
                                                        {client.cancelled_orders > 0 && (
                                                            <span className="text-muted-foreground ml-1 text-xs">
                                                                ({client.cancelled_orders} canc.)
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">{formatCurrency(client.average_ticket)}</TableCell>
                                                    <TableCell className="text-right tabular-nums">{formatCurrency(client.total_spent)}</TableCell>
                                                    <TableCell className="text-muted-foreground text-xs">
                                                        {formatRelative(client.last_order_at)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <SegmentBadge segment={client.segment} />
                                                    </TableCell>
                                                    <TableCell>
                                                        {client.tags.length === 0 ? (
                                                            <span className="text-muted-foreground text-xs">—</span>
                                                        ) : (
                                                            <div className="flex flex-wrap gap-1">
                                                                {client.tags.slice(0, 3).map((t) => (
                                                                    <span key={t} className="bg-muted rounded-full px-2 py-0.5 text-xs">
                                                                        #{t}
                                                                    </span>
                                                                ))}
                                                                {client.tags.length > 3 && (
                                                                    <span className="text-muted-foreground text-xs">+{client.tags.length - 3}</span>
                                                                )}
                                                            </div>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </>
                        )}

                        {meta && meta.last_page > 1 && (
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    {meta.total} contactos · página {meta.current_page} de {meta.last_page}
                                </span>
                                <div className="flex items-center gap-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => goToPage(meta.current_page - 1)}
                                        disabled={meta.current_page <= 1}
                                    >
                                        <ChevronLeft className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => goToPage(meta.current_page + 1)}
                                        disabled={meta.current_page >= meta.last_page}
                                    >
                                        <ChevronRight className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </PageShell>
    );
}
