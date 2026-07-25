import InputError from '@/components/input-error';
import ImageUploadZone from '@/components/menu/image-upload-zone';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useToast } from '@/components/ui/toast';
import { apiFetch } from '@/lib/api';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { cn } from '@/lib/utils';
import type { MenuItem } from '@/types';
import { useState } from 'react';

function MarginIndicator({ price, cost }: { price: string; cost: string }) {
    const p = parseFloat(price);
    const c = parseFloat(cost);
    if (!Number.isFinite(p) || p <= 0 || cost === '' || !Number.isFinite(c)) {
        return null;
    }
    if (c > p) {
        return <p className="text-xs font-medium text-[color:var(--color-status-critical)]">⚠ El costo supera el precio (margen negativo).</p>;
    }
    const marginAmount = p - c;
    const marginPct = (marginAmount / p) * 100;
    let cls = 'text-[color:var(--color-status-safe)]';
    if (marginPct < 20) cls = 'text-[color:var(--color-status-critical)]';
    else if (marginPct < 40) cls = 'text-[color:var(--color-status-warning)]';
    return (
        <p className={cn('text-xs font-medium', cls)}>
            Margen: {marginPct.toFixed(1)}% ({Math.round(marginAmount).toLocaleString('es-CO')} COP)
        </p>
    );
}

interface ItemFormModalProps {
    menuId: string;
    categoryId: string;
    item?: MenuItem | null;
    onClose: () => void;
    onSaved: (item: MenuItem) => void;
}

export default function ItemFormModal({ menuId, categoryId, item, onClose, onSaved }: ItemFormModalProps) {
    const isEditing = !!item;
    const { showToast } = useToast();
    const [name, setName] = useState(item?.name ?? '');
    const [description, setDescription] = useState(item?.description ?? '');
    const [price, setPrice] = useState(item ? String(item.price) : '');
    const [cost, setCost] = useState(item?.cost !== null && item?.cost !== undefined ? String(Math.round(item.cost)) : '');
    const [available, setAvailable] = useState(item?.available ?? true);
    const [selectedImage, setSelectedImage] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setErrors({});
        setSubmitting(true);

        const url = isEditing
            ? `/api/v1/menus/${menuId}/categories/${categoryId}/items/${item.id}`
            : `/api/v1/menus/${menuId}/categories/${categoryId}/items`;

        const body: Record<string, unknown> = {
            name,
            description,
            price: parseFloat(price),
            cost: cost === '' ? null : parseFloat(cost),
            available,
        };

        try {
            const res = await apiFetch(url, {
                method: isEditing ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });

            const data = await res.json();

            if (!res.ok) {
                setErrors(data.errors ?? { name: [data.message ?? 'Error al guardar.'] });
                setSubmitting(false);
                return;
            }

            const savedItem = data.data;

            // Upload image separately if provided
            if (selectedImage) {
                const formData = new FormData();
                formData.append('image', selectedImage);

                const imageRes = await apiFetch(`/api/v1/menus/${menuId}/items/${savedItem.id}/image`, {
                    method: 'POST',
                    body: formData,
                });

                if (!imageRes.ok) {
                    // El ítem YA quedó guardado: si dejamos el modal en modo crear,
                    // el reintento duplicaría el ítem. Cerramos vía onSaved y
                    // reportamos el fallo de imagen como toast aparte.
                    const imageData = await imageRes.json().catch(() => ({}));
                    showToast(
                        'error',
                        imageData.errors?.image?.[0] ?? imageData.message ?? 'El ítem se guardó, pero no se pudo subir la imagen. Edítalo para reintentar.',
                    );
                    onSaved(savedItem);
                    return;
                }

                const imageResult = await imageRes.json();
                onSaved(imageResult.data);
            } else {
                onSaved(savedItem);
            }
        } catch {
            setErrors({ name: ['Error de conexión.'] });
            setSubmitting(false);
        }
    }

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEditing ? 'Editar ítem' : 'Nuevo ítem'}</DialogTitle>
                </DialogHeader>

                <form noValidate onSubmit={handleSubmit} className="space-y-5">
                    <div className="space-y-1.5">
                        <Label htmlFor="item-name">Nombre *</Label>
                        <Input
                            id="item-name"
                            value={name}
                            onChange={(e) => setName(sanitizePlainText(e.target.value, 128, false, false))}
                            maxLength={128}
                            placeholder="Ej. Hamburguesa clásica"
                            required
                        />
                        <InputError message={errors.name?.[0]} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="item-description">Descripción</Label>
                        <Input
                            id="item-description"
                            value={description}
                            onChange={(e) => setDescription(sanitizePlainText(e.target.value, 512, false, false))}
                            maxLength={512}
                            placeholder="Descripción opcional"
                        />
                        <InputError message={errors.description?.[0]} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="item-price">Precio (COP) *</Label>
                        <Input
                            id="item-price"
                            type="number"
                            min="1"
                            step="1"
                            value={price}
                            onChange={(e) => setPrice(e.target.value)}
                            placeholder="0"
                            required
                        />
                        <InputError message={errors.price?.[0]} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="item-cost" className="flex items-center gap-2">
                            Costo (COP)
                            {item?.has_recipe && (
                                <span className="rounded bg-[color:var(--color-status-safe)]/15 px-1.5 py-0.5 text-[10px] font-medium text-[color:var(--color-status-safe)]">
                                    desde receta
                                </span>
                            )}
                        </Label>
                        <Input
                            id="item-cost"
                            type="number"
                            min="0"
                            step="1"
                            value={cost}
                            onChange={(e) => setCost(e.target.value)}
                            placeholder={item?.has_recipe ? 'Calculado automáticamente' : 'Opcional — para calcular margen'}
                            className="disabled:bg-muted/40"
                            readOnly={item?.has_recipe}
                            disabled={item?.has_recipe}
                        />
                        {item?.has_recipe && (
                            <p className="text-muted-foreground text-xs">
                                El costo se calcula a partir de la receta (BOM). Edita la receta desde el botón con icono{' '}
                                <span className="font-medium">chef</span>.
                            </p>
                        )}
                        <MarginIndicator price={price} cost={cost} />
                        <InputError message={errors.cost?.[0]} />
                    </div>

                    <div className="space-y-1.5">
                        <Label>Imagen del ítem</Label>
                        <ImageUploadZone onImageSelected={setSelectedImage} initialImage={item?.image_url} />
                        <InputError message={errors.image?.[0]} />
                    </div>

                    <div className="flex items-center gap-3">
                        <input
                            id="item-available"
                            type="checkbox"
                            checked={available}
                            onChange={(e) => setAvailable(e.target.checked)}
                            className="border-input accent-primary h-4 w-4 rounded"
                        />
                        <Label htmlFor="item-available" className="cursor-pointer">
                            Disponible para pedidos
                        </Label>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        {/* Guard cliente: precio > 0 (espeja `price: numeric|min:1` del backend; evita NaN→null). */}
                        <Button type="submit" disabled={submitting || !(Number(price) > 0)}>
                            {submitting ? 'Guardando…' : isEditing ? 'Guardar cambios' : 'Crear ítem'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
