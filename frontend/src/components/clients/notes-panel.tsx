import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { useToast } from '@/components/ui/toast';
import type { ClientNote } from '@/hooks/use-client';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface NotesPanelProps {
    notes: ClientNote[];
    canEdit: boolean;
    canDelete: boolean;
    onAdd: (note: string) => Promise<unknown>;
    onDelete: (id: string) => Promise<void>;
}

export function NotesPanel({ notes, canEdit, canDelete, onAdd, onDelete }: NotesPanelProps) {
    const { showToast } = useToast();
    const [drafting, setDrafting] = useState(false);
    const [draft, setDraft] = useState('');
    const [saving, setSaving] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState<ClientNote | null>(null);

    async function handleAdd() {
        if (draft.trim() === '') return;
        setSaving(true);
        try {
            await onAdd(draft.trim());
            setDraft('');
            setDrafting(false);
            showToast('success', 'Nota agregada.');
        } catch (err) {
            const msg = (err as { message?: string })?.message ?? 'No se pudo agregar la nota.';
            showToast('error', msg);
        } finally {
            setSaving(false);
        }
    }

    async function handleDelete() {
        if (!confirmDelete) return;
        try {
            await onDelete(confirmDelete.id);
            showToast('success', 'Nota eliminada.');
            setConfirmDelete(null);
        } catch (err) {
            const msg = (err as { message?: string })?.message ?? 'No se pudo eliminar.';
            showToast('error', msg);
        }
    }

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold">Notas privadas</h3>
                {canEdit && !drafting && (
                    <Button size="sm" variant="outline" onClick={() => setDrafting(true)}>
                        <Plus className="mr-1 h-4 w-4" />
                        Agregar
                    </Button>
                )}
            </div>

            {drafting && (
                <div className="space-y-2 rounded border p-3">
                    <textarea
                        className="bg-background focus:ring-ring w-full rounded border p-2 text-sm focus:ring-1 focus:outline-none"
                        rows={3}
                        maxLength={2000}
                        placeholder="Alergias, preferencias, observaciones..."
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        autoFocus
                    />
                    <div className="flex justify-end gap-2">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setDraft('');
                                setDrafting(false);
                            }}
                            disabled={saving}
                        >
                            Cancelar
                        </Button>
                        <Button size="sm" onClick={handleAdd} disabled={saving || draft.trim() === ''}>
                            Guardar
                        </Button>
                    </div>
                </div>
            )}

            {notes.length === 0 && !drafting ? (
                <p className="text-muted-foreground text-sm italic">Sin notas registradas.</p>
            ) : (
                <ul className="space-y-2">
                    {notes.map((note) => (
                        <li key={note.id} className="group relative rounded border p-3 text-sm">
                            <p className="whitespace-pre-wrap">{note.note}</p>
                            <div className="text-muted-foreground mt-2 flex items-center justify-between text-xs">
                                <span>
                                    {note.author?.name ?? 'Sistema'} ·{' '}
                                    {note.created_at ? new Date(note.created_at).toLocaleString('es-CO', { timeZone: 'America/Bogota' }) : '—'}
                                </span>
                                {canDelete && (
                                    <button
                                        type="button"
                                        onClick={() => setConfirmDelete(note)}
                                        className="text-muted-foreground hover:text-destructive opacity-0 transition-opacity group-hover:opacity-100"
                                        title="Eliminar nota"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <ConfirmDialog
                open={confirmDelete !== null}
                title="Eliminar nota"
                message="¿Eliminar esta nota privada del cliente? La acción quedará en la auditoría."
                confirmLabel="Eliminar"
                onConfirm={handleDelete}
                onCancel={() => setConfirmDelete(null)}
            />
        </div>
    );
}
