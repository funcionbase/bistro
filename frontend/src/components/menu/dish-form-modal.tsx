import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';
import type { MenuItem } from '@/types';
import { UtensilsCrossed } from 'lucide-react';
import { useRef, useState } from 'react';

interface DishFormModalProps {
    menuId: string;
    categoryId: string;
    dish?: MenuItem | null;
    onClose: () => void;
    onSaved: (dish: MenuItem) => void;
}

export default function DishFormModal({ menuId, categoryId, dish, onClose, onSaved }: DishFormModalProps) {
    const isEditing = !!dish;
    const [name, setName] = useState(dish?.name ?? '');
    const [description, setDescription] = useState(dish?.description ?? '');
    const [price, setPrice] = useState(dish ? String(dish.price) : '');
    const [available, setAvailable] = useState(dish?.available ?? true);
    const [imagePreview, setImagePreview] = useState<string | null>(dish?.image_url ?? null);
    // null/'' = heredar del default de empresa.
    const [taxRateOverride, setTaxRateOverride] = useState<string>(
        dish?.tax_rate !== null && dish?.tax_rate !== undefined ? String(dish.tax_rate) : '',
    );
    const [taxLabelOverride, setTaxLabelOverride] = useState<string>(dish?.tax_label ?? '');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [submitting, setSubmitting] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const activeCompany = useSharedData().activeCompany;
    const companyDefaultRate = activeCompany?.default_tax_rate ?? 0;
    const companyDefaultLabel = activeCompany?.default_tax_label ?? 'Sin IVA';

    function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (file) {
            setImagePreview(URL.createObjectURL(file));
        }
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setErrors({});
        setSubmitting(true);

        const url = isEditing
            ? `/api/v1/menus/${menuId}/categories/${categoryId}/items/${dish.id}`
            : `/api/v1/menus/${menuId}/categories/${categoryId}/items`;

        const formData = new FormData();
        formData.append('name', name);
        formData.append('description', description);
        formData.append('price', price);
        formData.append('available', available ? '1' : '0');
        if (taxRateOverride.trim() === '') {
            // Cadena vacía → omitir, hereda default de empresa. Si era un override
            // previo y el usuario lo borró, enviamos explícitamente null.
            if (isEditing && dish?.tax_rate !== null && dish?.tax_rate !== undefined) {
                formData.append('tax_rate', '');
            }
        } else {
            formData.append('tax_rate', taxRateOverride);
        }
        if (taxLabelOverride.trim() === '') {
            if (isEditing && dish?.tax_label) {
                formData.append('tax_label', '');
            }
        } else {
            formData.append('tax_label', taxLabelOverride.trim());
        }
        if (fileInputRef.current?.files?.[0]) {
            formData.append('image', fileInputRef.current.files[0]);
        }
        if (isEditing) {
            formData.append('_method', 'PUT');
        }

        try {
            const res = await apiFetch(url, {
                method: 'POST',
                body: formData,
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
            <DialogContent className="max-h-[90vh] overflow-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEditing ? 'Editar plato' : 'Nuevo plato'}</DialogTitle>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="flex gap-4">
                        <button
                            type="button"
                            className="bg-muted flex h-24 w-24 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-lg hover:opacity-80"
                            onClick={() => fileInputRef.current?.click()}
                        >
                            {imagePreview ? (
                                <img src={imagePreview} alt="Preview" className="h-full w-full object-cover" />
                            ) : (
                                <UtensilsCrossed className="text-muted-foreground/50 h-8 w-8" />
                            )}
                        </button>
                        <div className="flex-1 space-y-1.5">
                            <Label htmlFor="dish-name">Nombre *</Label>
                            <Input id="dish-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Ej. Hamburguesa clásica" />
                            <InputError message={errors.name?.[0]} />
                        </div>
                    </div>

                    <input ref={fileInputRef} type="file" accept="image/jpg,image/jpeg,image/png" className="hidden" onChange={handleFileChange} />
                    <InputError message={errors.image?.[0]} />

                    <div className="space-y-1.5">
                        <Label htmlFor="dish-description">Descripción</Label>
                        <Input
                            id="dish-description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            placeholder="Descripción opcional"
                        />
                        <InputError message={errors.description?.[0]} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="dish-price">Precio (COP) *</Label>
                        <Input
                            id="dish-price"
                            type="number"
                            min="0"
                            step="1"
                            value={price}
                            onChange={(e) => setPrice(e.target.value)}
                            placeholder="0"
                        />
                        <InputError message={errors.price?.[0]} />
                    </div>

                    <div className="flex items-center gap-3">
                        <input
                            id="dish-available"
                            type="checkbox"
                            checked={available}
                            onChange={(e) => setAvailable(e.target.checked)}
                            className="border-input accent-primary h-4 w-4 rounded"
                        />
                        <Label htmlFor="dish-available" className="cursor-pointer">
                            Disponible para pedidos
                        </Label>
                    </div>

                    <div className="border-border rounded-md border border-dashed p-3">
                        <div className="mb-2 text-xs font-medium">Impuesto del ítem (opcional)</div>
                        <p className="text-muted-foreground mb-2 text-xs">
                            Si lo dejas vacío, hereda el default de la empresa:{' '}
                            <span className="font-semibold">
                                {companyDefaultLabel} ({companyDefaultRate}%)
                            </span>
                            . Útil para casos como bebida alcohólica con IVA 19% mientras la comida usa INC 8%.
                        </p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="dish-tax-rate" className="text-xs">
                                    Tasa (%)
                                </Label>
                                <Input
                                    id="dish-tax-rate"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="1"
                                    value={taxRateOverride}
                                    onChange={(e) => setTaxRateOverride(e.target.value)}
                                    placeholder={`${companyDefaultRate}`}
                                />
                                <InputError message={errors.tax_rate?.[0]} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="dish-tax-label" className="text-xs">
                                    Etiqueta
                                </Label>
                                <Input
                                    id="dish-tax-label"
                                    value={taxLabelOverride}
                                    onChange={(e) => setTaxLabelOverride(e.target.value)}
                                    placeholder={companyDefaultLabel}
                                    maxLength={60}
                                />
                                <InputError message={errors.tax_label?.[0]} />
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Guardando…' : isEditing ? 'Guardar cambios' : 'Crear plato'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
