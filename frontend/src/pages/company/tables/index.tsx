import { MenuQrPoster } from '@/components/company/menu-qr-poster';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ListCardSkeleton } from '@/components/ui/list-card-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';

import { AlertCircle, ExternalLink, Pencil, Plus, QrCode, RefreshCw, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface TableRow {
    id: string;
    number: string;
    capacity: number;
    qr_token: string;
    status: 'available' | 'occupied' | 'reserved' | 'blocked';
    archived_at: string | null;
}


/**
 * Admin de mesas físicas (#191 Fase 8). CRUD básico.
 *
 * El QR de cada mesa codifica `/menus/{nit}?table={number}` — el mismo
 * esquema que el QR genérico del menú en "Mi empresa". Ventaja: si el
 * `number` cambia, el QR sigue funcionando sin regenerar. La columna
 * `tables.qr_token` se conserva en BD para el flujo opcional de sesión
 * grupal `/t/{qr_token}` (#191), pero el poster impreso usa la URL
 * estándar del menú.
 */
export default function CompanyTablesIndex() {
    const activeCompany = useSharedData().activeCompany;
    const [tables, setTables] = useState<TableRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const [editing, setEditing] = useState<{ id: string | null; number: string | null; capacity: number } | null>(null);
    const [confirmArchive, setConfirmArchive] = useState<TableRow | null>(null);
    const [posterFor, setPosterFor] = useState<TableRow | null>(null);
    const [genericPosterOpen, setGenericPosterOpen] = useState(false);

    const fetchTables = useCallback(async () => {
        try {
            const resp = await apiFetch('/api/v1/tables');
            if (!resp.ok) throw new Error('No pudimos cargar las mesas.');
            const json = (await resp.json()) as { data: TableRow[] };
            setTables(json.data);
            setError(null);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void fetchTables();
    }, [fetchTables]);

    const submitEdit = async () => {
        if (!editing) return;
        setBusy(true);
        setActionError(null);
        try {
            const isCreate = editing.id === null;
            const url = isCreate ? '/api/v1/tables' : `/api/v1/tables/${editing.id}`;
            const method = isCreate ? 'POST' : 'PATCH';
            // Backend asigna el número automáticamente al crear; al editar
            // sólo se manda capacidad. El número no se envía nunca desde UI.
            const resp = await apiFetch(url, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ capacity: editing.capacity }),
            });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'Acción rechazada.');
            }
            await fetchTables();
            setEditing(null);
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setBusy(false);
        }
    };

    const archive = async (row: TableRow) => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/tables/${row.id}`, { method: 'DELETE' });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'No se pudo desactivar.');
            }
            await fetchTables();
            setConfirmArchive(null);
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setBusy(false);
        }
    };

    const restore = async (row: TableRow) => {
        setBusy(true);
        setActionError(null);
        try {
            const resp = await apiFetch(`/api/v1/tables/${row.id}/restore`, { method: 'POST' });
            if (!resp.ok) {
                const data = (await resp.json().catch(() => ({}))) as { message?: string };
                throw new Error(data.message ?? 'No se pudo reactivar.');
            }
            await fetchTables();
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'Error.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <PageShell title="Mesas">
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Administración"
                    title="Mesas físicas"
                    description="Cada sede tiene su propia configuración de mesas. Genera aquí el QR del menú general y el QR específico de cada mesa."
                    actions={
                        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                            <Button type="button" variant="outline" size="sm" asChild className="flex-1 sm:flex-initial">
                                <a href="/orders/tables">
                                    <ExternalLink className="mr-1.5 h-3.5 w-3.5" /> Vista operativa
                                </a>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setGenericPosterOpen(true)}
                                disabled={!activeCompany?.nit}
                                className="flex-1 sm:flex-initial"
                            >
                                <QrCode className="mr-1.5 h-3.5 w-3.5" /> QR del menú
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                onClick={() => void fetchTables()}
                                disabled={loading || busy}
                                className="flex-1 sm:flex-initial"
                            >
                                <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Refrescar
                            </Button>
                            <Button
                                type="button"
                                onClick={() => setEditing({ id: null, number: '', capacity: 4 })}
                                disabled={busy}
                                className="w-full sm:w-auto"
                            >
                                <Plus className="mr-1.5 h-4 w-4" /> Nueva mesa
                            </Button>
                        </div>
                    }
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {actionError && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{actionError}</AlertDescription>
                    </Alert>
                )}

                {loading ? (
                    <ListCardSkeleton rows={6} actions={3} variant="card" gridClassName="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" />
                ) : tables.length === 0 ? (
                    <EmptyState
                        icon={QrCode}
                        title="Sin mesas"
                        description="Crea la primera mesa para imprimir su QR e iniciar el flujo de pedido por mesa."
                    />
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {tables.map((row) => (
                            <article key={row.id} className="border-border bg-card text-card-foreground space-y-2 rounded-2xl border p-4">
                                <div className="flex items-center justify-between gap-2">
                                    <h3 className="text-foreground text-lg font-semibold">Mesa {row.number}</h3>
                                    <div className="flex items-center gap-1.5">
                                        {row.archived_at ? (
                                            <Badge
                                                variant="secondary"
                                                className="bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]"
                                            >
                                                Desactivada
                                            </Badge>
                                        ) : (
                                            <Badge variant="secondary">
                                                {row.status === 'occupied' ? 'Ocupada' : row.status === 'available' ? 'Libre' : row.status}
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                                <p className="text-muted-foreground text-xs">Capacidad: {row.capacity} comensales</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {!row.archived_at && (
                                        <>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => setPosterFor(row)}
                                                disabled={busy}
                                                className="h-9 px-3 text-sm"
                                            >
                                                <QrCode className="mr-1 h-4 w-4" /> QR
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() =>
                                                    setEditing({
                                                        id: row.id,
                                                        number: row.number,
                                                        capacity: row.capacity,
                                                    })
                                                }
                                                disabled={busy}
                                                className="h-9 px-3 text-sm"
                                            >
                                                <Pencil className="mr-1 h-4 w-4" /> Editar
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() => setConfirmArchive(row)}
                                                disabled={busy}
                                                className="text-destructive hover:text-destructive h-9 px-3 text-sm"
                                            >
                                                <Trash2 className="mr-1 h-4 w-4" /> Desactivar
                                            </Button>
                                        </>
                                    )}
                                    {row.archived_at && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => void restore(row)}
                                            disabled={busy}
                                            className="h-9 px-3 text-sm"
                                        >
                                            <RefreshCw className="mr-1 h-4 w-4" /> Reactivar
                                        </Button>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </div>

            <Dialog open={!!editing} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{editing?.id === null ? 'Nueva mesa' : `Editar mesa ${editing?.number ?? ''}`}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        {editing?.id === null ? (
                            <p className="text-muted-foreground bg-muted/40 border-border rounded-md border px-3 py-2 text-xs">
                                El número se asigna automáticamente como el siguiente disponible en esta sede. Si desactivas una mesa, la secuencia se
                                compacta sola.
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-xs">
                                El número de mesa no se edita manualmente — se mantiene el de la secuencia.
                            </p>
                        )}
                        <div>
                            <Label htmlFor="capacity">Capacidad</Label>
                            <Input
                                id="capacity"
                                type="number"
                                min={1}
                                max={30}
                                value={editing?.capacity ?? 4}
                                onChange={(e) =>
                                    setEditing((prev) => (prev ? { ...prev, capacity: Number.parseInt(e.target.value, 10) || 1 } : prev))
                                }
                            />
                        </div>
                        <Button type="button" className="w-full" onClick={() => void submitEdit()} disabled={busy}>
                            {editing?.id === null ? 'Crear mesa' : 'Guardar cambios'}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={!!posterFor} onOpenChange={(o) => !o && setPosterFor(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>QR de Mesa {posterFor?.number}</DialogTitle>
                    </DialogHeader>
                    {posterFor && activeCompany?.nit && (
                        <MenuQrPoster
                            nit={activeCompany?.nit}
                            commercialName={activeCompany?.name ?? 'Mesa'}
                            logoUrl={activeCompany?.logo_url ?? null}
                            primaryColor={activeCompany?.brand_color ?? '#0F172A'}
                            mode="menu"
                            tableNumber={posterFor.number}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={genericPosterOpen} onOpenChange={setGenericPosterOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>QR del menú</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground -mt-2 mb-2 text-xs">
                        QR general de la empresa (sin mesa). Sirve para imprimir en barras, entrada o cualquier punto donde no haya una mesa
                        específica.
                    </p>
                    {activeCompany?.nit && (
                        <MenuQrPoster
                            nit={activeCompany?.nit}
                            commercialName={activeCompany?.name ?? 'Empresa'}
                            logoUrl={activeCompany?.logo_url ?? null}
                            primaryColor={activeCompany?.brand_color ?? '#0F172A'}
                            mode="menu"
                            tableNumber={null}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={!!confirmArchive}
                title={`¿Desactivar Mesa ${confirmArchive?.number ?? ''}?`}
                message="La mesa queda fuera del flujo operativo y las demás se renumeran. Los registros históricos se conservan y puedes reactivarla cuando quieras."
                confirmLabel="Desactivar"
                onConfirm={() => confirmArchive && void archive(confirmArchive)}
                onCancel={() => setConfirmArchive(null)}
                loading={busy}
            />
        </PageShell>
    );
}
