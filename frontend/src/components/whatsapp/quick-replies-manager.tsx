import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ui/toast';
import { useQuickReplies, type QuickReply } from '@/hooks/use-quick-replies';
import type { Branch } from '@/types';

import { AlertTriangle, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

/**
 * Gestión de respuestas rápidas (§8.4b punto 7).
 *
 * Solo owner/admin la ve (el backend responde 403 a los demás). Alcance por
 * sede: null = de toda la empresa; con sede = solo esa. El alcance se fija al
 * crear y no se mueve al editar —evita que una respuesta cambie de sede bajo el
 * operador que la usa—, así que el selector solo aparece en "nueva".
 */
export function QuickRepliesManager({ token, branches }: { token: string | null; branches: Branch[] }) {
    const { replies, loading, error, create, update, remove } = useQuickReplies(token);
    const { showToast } = useToast();

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<QuickReply | null>(null);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [branchId, setBranchId] = useState('');
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const [pendingDelete, setPendingDelete] = useState<QuickReply | null>(null);

    const openNew = () => {
        setEditing(null);
        setTitle('');
        setBody('');
        setBranchId('');
        setFormError(null);
        setFormOpen(true);
    };

    const openEdit = (reply: QuickReply) => {
        setEditing(reply);
        setTitle(reply.title);
        setBody(reply.body);
        setBranchId(reply.branch_id ?? '');
        setFormError(null);
        setFormOpen(true);
    };

    const save = async (e: React.FormEvent) => {
        e.preventDefault();
        if (saving) return;
        setSaving(true);
        setFormError(null);
        try {
            if (editing) {
                await update(editing.id, { title: title.trim(), body: body.trim() });
            } else {
                await create({ title: title.trim(), body: body.trim(), branch_id: branchId || null });
            }
            setFormOpen(false);
            showToast('success', 'Respuesta rápida guardada');
        } catch (err) {
            setFormError(err instanceof Error ? err.message : 'No se pudo guardar.');
        } finally {
            setSaving(false);
        }
    };

    const confirmDelete = async () => {
        if (!pendingDelete) return;
        try {
            await remove(pendingDelete.id);
            showToast('success', 'Respuesta rápida eliminada');
        } catch {
            showToast('error', 'No se pudo eliminar');
        } finally {
            setPendingDelete(null);
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold tracking-tight">Respuestas rápidas</h2>
                    <p className="text-muted-foreground text-sm">
                        Frases que el operador inserta escribiendo <code className="bg-muted rounded px-1">/</code> en el chat. Admiten{' '}
                        <code className="bg-muted rounded px-1">{'{{cliente}}'}</code>, <code className="bg-muted rounded px-1">{'{{pedido}}'}</code>{' '}
                        y <code className="bg-muted rounded px-1">{'{{sede}}'}</code>.
                    </p>
                </div>
                <Button size="sm" onClick={openNew} className="shrink-0">
                    <Plus className="mr-2 h-4 w-4" />
                    Agregar
                </Button>
            </div>

            {error && (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            {loading ? (
                <div className="space-y-2">
                    <Skeleton className="h-16 w-full" />
                    <Skeleton className="h-16 w-full" />
                </div>
            ) : replies.length === 0 ? (
                <p className="text-muted-foreground border-border rounded-lg border border-dashed p-4 text-center text-sm">
                    Todavía no hay respuestas rápidas. Creá las cinco frases que más repetís y ahorrale tiempo al operador.
                </p>
            ) : (
                <div className="space-y-2">
                    {replies.map((reply) => (
                        <div key={reply.id} className="border-border flex items-start justify-between gap-3 rounded-lg border p-3">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <p className="truncate text-sm font-medium">{reply.title}</p>
                                    <Badge variant="outline" className="shrink-0 text-[10px]">
                                        {reply.branch_name ?? 'Empresa'}
                                    </Badge>
                                </div>
                                <p className="text-muted-foreground truncate text-xs">{reply.body}</p>
                            </div>
                            <div className="flex shrink-0 gap-1">
                                <Button variant="ghost" size="icon" onClick={() => openEdit(reply)} aria-label={`Editar ${reply.title}`}>
                                    <Pencil className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="text-destructive"
                                    onClick={() => setPendingDelete(reply)}
                                    aria-label={`Eliminar ${reply.title}`}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Editar respuesta rápida' : 'Nueva respuesta rápida'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={save} className="space-y-3" noValidate>
                        <div className="space-y-1">
                            <Label htmlFor="qr-title">Título</Label>
                            <Input
                                id="qr-title"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                maxLength={80}
                                placeholder="Ej. Tiempo de entrega"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="qr-body">Mensaje</Label>
                            <textarea
                                id="qr-body"
                                value={body}
                                onChange={(e) => setBody(e.target.value)}
                                rows={3}
                                maxLength={2000}
                                placeholder="Ej. Hola {{cliente}}, tu pedido {{pedido}} sale en 35 minutos."
                                className="border-input bg-background focus:border-ring w-full rounded-lg border px-3 py-2 text-sm focus:outline-none"
                            />
                            <p className="text-muted-foreground text-xs">
                                Variables: <code className="bg-muted rounded px-1">{'{{cliente}}'}</code>,{' '}
                                <code className="bg-muted rounded px-1">{'{{pedido}}'}</code>,{' '}
                                <code className="bg-muted rounded px-1">{'{{sede}}'}</code>.
                            </p>
                        </div>
                        {!editing && (
                            <div className="space-y-1">
                                <Label htmlFor="qr-scope">Alcance</Label>
                                <select
                                    id="qr-scope"
                                    value={branchId}
                                    onChange={(e) => setBranchId(e.target.value)}
                                    className="border-input bg-background focus:border-ring w-full rounded-lg border px-3 py-2 text-sm focus:outline-none"
                                >
                                    <option value="">Toda la empresa</option>
                                    {branches.map((branch) => (
                                        <option key={branch.id} value={branch.id}>
                                            {branch.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        {formError && <p className="text-destructive text-xs">{formError}</p>}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setFormOpen(false)} disabled={saving}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={saving || !title.trim() || !body.trim()}>
                                {saving ? 'Guardando…' : 'Guardar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={pendingDelete !== null}
                title="Eliminar respuesta rápida"
                message={`Se eliminará «${pendingDelete?.title ?? ''}». Los operadores dejarán de verla en el menú.`}
                confirmLabel="Eliminar"
                onConfirm={() => void confirmDelete()}
                onCancel={() => setPendingDelete(null)}
            />
        </div>
    );
}
