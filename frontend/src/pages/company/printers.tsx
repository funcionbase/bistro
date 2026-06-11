import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ListCardSkeleton } from '@/components/ui/list-card-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useToast } from '@/components/ui/toast';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';

import { AlertCircle, LoaderCircle, Pencil, Plus, Printer as PrinterIcon, Trash2, Zap } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Printer {
    id: string;
    name: string;
    type: string;
    type_label: string;
    connection: string;
    connection_label: string;
    address: string;
    paper_width: number;
    categories: string[];
    is_active: boolean;
    last_test_at: string | null;
}

interface FormState {
    id?: string;
    name: string;
    type: string;
    connection: string;
    address: string;
    paper_width: number;
    categories: string;
    is_active: boolean;
}


const EMPTY_FORM: FormState = {
    name: '',
    type: 'kitchen',
    connection: 'lan',
    address: '',
    paper_width: 80,
    categories: '',
    is_active: true,
};

export default function CompanyPrinters() {
    const printingConfig = useSharedData().printingConfig ?? {
        types: {},
        connections: {},
        paper_widths: [],
    };
    const token = useToken();
    const { showToast } = useToast();
    const [printers, setPrinters] = useState<Printer[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const [confirmDelete, setConfirmDelete] = useState<Printer | null>(null);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => {
        if (!token) {
            setLoading(false);
            return;
        }
        loadPrinters();
    }, [token]);

    async function loadPrinters() {
        setLoading(true);
        setError(null);
        try {
            const res = await apiFetch('/api/v1/company/printers');
            if (!res.ok) throw new Error('No se pudo cargar la lista');
            const data = await res.json();
            setPrinters(data.printers ?? []);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Error desconocido');
        } finally {
            setLoading(false);
        }
    }

    function openCreate() {
        setForm(EMPTY_FORM);
        setFormError(null);
        setModalOpen(true);
    }

    function openEdit(p: Printer) {
        setForm({
            id: p.id,
            name: p.name,
            type: p.type,
            connection: p.connection,
            address: p.address,
            paper_width: p.paper_width,
            categories: (p.categories ?? []).join(', '),
            is_active: p.is_active,
        });
        setFormError(null);
        setModalOpen(true);
    }

    const submit: FormEventHandler<HTMLFormElement> = async (e) => {
        e.preventDefault();
        setSaving(true);
        setFormError(null);
        const payload = {
            name: form.name,
            type: form.type,
            connection: form.connection,
            address: form.address,
            paper_width: form.paper_width,
            categories: form.categories
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean),
            is_active: form.is_active,
        };
        const url = form.id ? `/api/v1/company/printers/${form.id}` : '/api/v1/company/printers';
        const method = form.id ? 'PUT' : 'POST';
        try {
            const res = await apiFetch(url, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data.message ?? 'No se pudo guardar la impresora');
            }
            setModalOpen(false);
            await loadPrinters();
            showToast('success', form.id ? 'Impresora actualizada.' : 'Impresora creada.');
        } catch (e) {
            setFormError(e instanceof Error ? e.message : 'Error');
        } finally {
            setSaving(false);
        }
    };

    async function handleDelete() {
        if (!confirmDelete) return;
        setDeleting(true);
        const name = confirmDelete.name;
        try {
            const res = await apiFetch(`/api/v1/company/printers/${confirmDelete.id}`, { method: 'DELETE' });
            if (!res.ok) throw new Error('No se pudo eliminar la impresora');
            await loadPrinters();
            showToast('success', `Impresora "${name}" eliminada.`);
            setConfirmDelete(null);
        } catch (e) {
            showToast('error', e instanceof Error ? e.message : 'Error al eliminar.');
        } finally {
            setDeleting(false);
        }
    }

    async function test(p: Printer) {
        try {
            const res = await apiFetch(`/api/v1/company/printers/${p.id}/test`, { method: 'POST' });
            if (!res.ok) throw new Error('No se pudo encolar la prueba');
            showToast('success', `Prueba enviada a "${p.name}". Revisa la impresora.`);
        } catch (e) {
            showToast('error', e instanceof Error ? e.message : 'Error en la prueba.');
        }
    }

    const headerActions = (
        <Button size="sm" onClick={openCreate}>
            <Plus className="mr-1.5 h-4 w-4" />
            Nueva impresora
        </Button>
    );

    return (
        <PageShell title="Impresoras">
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="IMPRESORAS"
                    title="Impresoras térmicas"
                    description="Configura impresoras de cocina, barra y caja. Las comandas se envían automáticamente al destino correcto según la categoría del ítem."
                    actions={headerActions}
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {loading ? (
                    <ListCardSkeleton rows={4} actions={3} variant="card" gridClassName="grid gap-3 md:grid-cols-2" />
                ) : printers.length === 0 ? (
                    <Card className="rounded-2xl shadow-sm">
                        <CardContent className="p-0">
                            <EmptyState
                                icon={PrinterIcon}
                                title="No hay impresoras configuradas"
                                description="Conecta impresoras térmicas para imprimir comandas y recibos desde la app."
                                action={
                                    <Button onClick={openCreate} variant="outline" size="sm">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Agregar primera impresora
                                    </Button>
                                }
                            />
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2">
                        {printers.map((p) => (
                            <Card key={p.id} className="rounded-2xl shadow-sm">
                                <CardContent className="flex flex-col gap-4 p-5">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex min-w-0 items-start gap-3">
                                            <div className="bg-muted text-foreground shrink-0 rounded-lg p-2">
                                                <PrinterIcon className="h-4 w-4" />
                                            </div>
                                            <div className="min-w-0">
                                                <h3 className="text-foreground truncate text-sm font-semibold">{p.name}</h3>
                                                <p className="text-muted-foreground mt-0.5 text-xs">
                                                    {p.type_label} · {p.connection_label} · {p.paper_width}mm
                                                </p>
                                            </div>
                                        </div>
                                        <Badge variant={p.is_active ? 'safe' : 'secondary'} className="shrink-0">
                                            {p.is_active ? 'Activa' : 'Inactiva'}
                                        </Badge>
                                    </div>

                                    <dl className="space-y-1.5 text-sm">
                                        <div className="flex flex-wrap gap-x-2 break-all">
                                            <dt className="text-muted-foreground">Dirección:</dt>
                                            <dd className="text-foreground font-mono text-xs">{p.address}</dd>
                                        </div>
                                        <div className="flex flex-wrap items-baseline gap-x-2">
                                            <dt className="text-muted-foreground">Categorías:</dt>
                                            <dd>
                                                {p.categories.length === 0 ? (
                                                    <span className="text-muted-foreground italic">sin asignar</span>
                                                ) : (
                                                    <span className="text-foreground">{p.categories.join(', ')}</span>
                                                )}
                                            </dd>
                                        </div>
                                    </dl>

                                    <div className="border-border flex flex-wrap gap-1.5 border-t pt-3">
                                        <Button size="sm" variant="outline" onClick={() => test(p)}>
                                            <Zap className="mr-1 h-3 w-3" />
                                            Probar
                                        </Button>
                                        <Button size="sm" variant="outline" onClick={() => openEdit(p)}>
                                            <Pencil className="mr-1 h-3 w-3" />
                                            Editar
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="text-muted-foreground hover:text-destructive"
                                            onClick={() => setConfirmDelete(p)}
                                        >
                                            <Trash2 className="mr-1 h-3 w-3" />
                                            Eliminar
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            <Dialog open={modalOpen} onOpenChange={(open) => !open && !saving && setModalOpen(false)}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{form.id ? 'Editar impresora' : 'Nueva impresora'}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="flex flex-col gap-4">
                        {formError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{formError}</AlertDescription>
                            </Alert>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="printer-name">Nombre</Label>
                            <Input
                                id="printer-name"
                                value={form.name}
                                onChange={(e) => setForm({ ...form, name: e.target.value })}
                                required
                                maxLength={120}
                                placeholder="Ej: Cocina caliente, Barra, Caja"
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="printer-type">Tipo</Label>
                                <Select value={form.type} onValueChange={(v) => setForm({ ...form, type: v })}>
                                    <SelectTrigger id="printer-type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(printingConfig.types).map(([k, label]) => (
                                            <SelectItem key={k} value={k}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="printer-connection">Conexión</Label>
                                <Select value={form.connection} onValueChange={(v) => setForm({ ...form, connection: v })}>
                                    <SelectTrigger id="printer-connection">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(printingConfig.connections).map(([k, label]) => (
                                            <SelectItem key={k} value={k}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="printer-address">Dirección (URL del agente / IP:puerto / path USB)</Label>
                            <Input
                                id="printer-address"
                                value={form.address}
                                onChange={(e) => setForm({ ...form, address: e.target.value })}
                                placeholder="http://10.0.0.50:9100/print"
                                required
                                className="font-mono text-xs"
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="printer-paper-width">Ancho de papel</Label>
                            <Select value={String(form.paper_width)} onValueChange={(v) => setForm({ ...form, paper_width: parseInt(v, 10) })}>
                                <SelectTrigger id="printer-paper-width">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {printingConfig.paper_widths.map((w) => (
                                        <SelectItem key={w} value={String(w)}>
                                            {w}mm
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="printer-categories">Categorías (separadas por coma)</Label>
                            <Input
                                id="printer-categories"
                                value={form.categories}
                                onChange={(e) => setForm({ ...form, categories: e.target.value })}
                                placeholder="Salchipapas, Hamburguesas, Picadas"
                            />
                            <p className="text-muted-foreground text-xs">
                                Sólo aplica para impresoras de cocina/barra. La comanda se imprime aquí cuando un ítem pertenece a una de estas
                                categorías.
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            <Checkbox
                                id="printer-is-active"
                                checked={form.is_active}
                                onCheckedChange={(v) => setForm({ ...form, is_active: v === true })}
                            />
                            <Label htmlFor="printer-is-active" className="cursor-pointer">
                                Impresora activa
                            </Label>
                        </div>

                        <DialogFooter className="gap-2 pt-2 sm:gap-2">
                            <Button type="button" variant="outline" onClick={() => setModalOpen(false)} disabled={saving}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {saving ? <LoaderCircle className="h-4 w-4 animate-spin" /> : form.id ? 'Guardar cambios' : 'Crear impresora'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirmDelete !== null}
                title="Eliminar impresora"
                message={`¿Eliminar la impresora "${confirmDelete?.name ?? ''}"? Esta acción no se puede deshacer.`}
                confirmLabel="Eliminar"
                loading={deleting}
                onConfirm={handleDelete}
                onCancel={() => setConfirmDelete(null)}
            />
        </PageShell>
    );
}
