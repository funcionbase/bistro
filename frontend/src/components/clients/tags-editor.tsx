import { Input } from '@/components/ui/input';
import { useToast } from '@/components/ui/toast';
import type { ClientTag } from '@/hooks/use-client';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

interface TagsEditorProps {
    tags: ClientTag[];
    canEdit: boolean;
    canDelete: boolean;
    onAdd: (tag: string) => Promise<unknown>;
    onDelete: (id: string) => Promise<void>;
}

const TAG_REGEX = /^[a-z0-9_-]+$/;

export function TagsEditor({ tags, canEdit, canDelete, onAdd, onDelete }: TagsEditorProps) {
    const { showToast } = useToast();
    const [drafting, setDrafting] = useState(false);
    const [draft, setDraft] = useState('');
    const [saving, setSaving] = useState(false);

    async function handleAdd() {
        const tag = draft.trim().toLowerCase();
        if (tag === '') return;
        if (!TAG_REGEX.test(tag)) {
            showToast('error', 'Solo minúsculas, dígitos, "_" y "-".');
            return;
        }
        setSaving(true);
        try {
            await onAdd(tag);
            setDraft('');
            setDrafting(false);
        } catch (err) {
            const msg = (err as { message?: string })?.message ?? 'No se pudo agregar la etiqueta.';
            showToast('error', msg);
        } finally {
            setSaving(false);
        }
    }

    async function handleRemove(id: string) {
        try {
            await onDelete(id);
        } catch (err) {
            const msg = (err as { message?: string })?.message ?? 'No se pudo eliminar la etiqueta.';
            showToast('error', msg);
        }
    }

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                {tags.length === 0 && !drafting && <span className="text-muted-foreground text-xs italic">Sin etiquetas.</span>}
                {tags.map((tag) => (
                    <span key={tag.id} className="bg-muted inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs">
                        #{tag.tag}
                        {canDelete && (
                            <button
                                type="button"
                                onClick={() => handleRemove(tag.id)}
                                className="text-muted-foreground hover:text-destructive"
                                title="Quitar etiqueta"
                            >
                                <X className="h-3 w-3" />
                            </button>
                        )}
                    </span>
                ))}

                {canEdit && !drafting && (
                    <button
                        type="button"
                        onClick={() => setDrafting(true)}
                        className="border-muted-foreground/40 text-muted-foreground hover:bg-muted inline-flex items-center gap-1 rounded-full border border-dashed px-2.5 py-0.5 text-xs"
                    >
                        <Plus className="h-3 w-3" />
                        Etiqueta
                    </button>
                )}
            </div>

            {drafting && (
                <div className="flex items-center gap-2">
                    <Input
                        autoFocus
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                void handleAdd();
                            }
                            if (e.key === 'Escape') {
                                setDraft('');
                                setDrafting(false);
                            }
                        }}
                        placeholder="vip"
                        maxLength={50}
                        className="h-7 max-w-[160px] text-xs"
                        disabled={saving}
                    />
                    <button
                        type="button"
                        onClick={() => void handleAdd()}
                        disabled={saving || draft.trim() === ''}
                        className="text-primary text-xs hover:underline disabled:opacity-50"
                    >
                        Agregar
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setDraft('');
                            setDrafting(false);
                        }}
                        className="text-muted-foreground text-xs hover:underline"
                    >
                        Cancelar
                    </button>
                </div>
            )}
        </div>
    );
}
