import InputError from '@/components/input-error';
import { KdsStationSelect } from '@/components/kds/kds-station-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiFetch } from '@/lib/api';
import { sanitizePlainText } from '@/lib/input-sanitize';
import type { MenuCategory } from '@/types';
import { useState } from 'react';

interface CategoryFormModalProps {
    menuId: string;
    category?: MenuCategory | null;
    onClose: () => void;
    onSaved: (category: MenuCategory) => void;
}

export default function CategoryFormModal({ menuId, category, onClose, onSaved }: CategoryFormModalProps) {
    const isEditing = !!category;
    const [name, setName] = useState(category?.name ?? '');
    const [description, setDescription] = useState(category?.description ?? '');
    const [sortOrder, setSortOrder] = useState(String(category?.order ?? 0));
    const [kdsStationId, setKdsStationId] = useState<string | null>(category?.kds_station_id ?? null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setErrors({});
        setSubmitting(true);

        const url = isEditing ? `/api/v1/menus/${menuId}/categories/${category.id}` : `/api/v1/menus/${menuId}/categories`;

        try {
            const res = await apiFetch(url, {
                method: isEditing ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name,
                    description: description || null,
                    order: parseInt(sortOrder) || 0,
                    kds_station_id: kdsStationId,
                }),
            });

            const data = await res.json();

            if (!res.ok) {
                setErrors(data.errors ?? { name: [data.message ?? 'Error al guardar.'] });
                return;
            }

            onSaved(data.data);
        } catch {
            setErrors({ name: ['Error de conexión.'] });
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEditing ? 'Editar categoría' : 'Nueva categoría'}</DialogTitle>
                </DialogHeader>

                <form noValidate onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="cat-name">Nombre *</Label>
                        <Input
                            id="cat-name"
                            value={name}
                            onChange={(e) => setName(sanitizePlainText(e.target.value, 128, false, false))}
                            maxLength={128}
                            placeholder="Ej. Entradas"
                        />
                        <InputError message={errors.name?.[0]} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="cat-description">Descripción</Label>
                        <Input
                            id="cat-description"
                            value={description}
                            onChange={(e) => setDescription(sanitizePlainText(e.target.value, 512, false, false))}
                            maxLength={512}
                            placeholder="Descripción opcional"
                        />
                        <InputError message={errors.description?.[0]} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="cat-sort">Orden de visualización</Label>
                        <Input id="cat-sort" type="number" min="0" value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} />
                        <InputError message={errors.order?.[0]} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="cat-kds-station">Estación de cocina (KDS)</Label>
                        <KdsStationSelect id="cat-kds-station" value={kdsStationId} onChange={setKdsStationId} disabled={submitting} />
                        <p className="text-muted-foreground text-xs">
                            Cuando los items se aprueben, aparecerán en la pantalla de esta estación. Si dejas <em>Predeterminada</em>, caen en la
                            estación default de la sede.
                        </p>
                        <InputError message={errors.kds_station_id?.[0]} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Guardando…' : isEditing ? 'Guardar cambios' : 'Crear categoría'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
