import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { validateCouponCode } from '@/lib/coupon-helpers';
import { generateCouponCode } from '@/lib/generate-coupon-code';
import { cn } from '@/lib/utils';
import type { Coupon, CouponFormData } from '@/types/coupon';
import { LoaderCircle, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface CouponFormProps {
    coupon?: Coupon | null;
    onSubmit: (data: Partial<CouponFormData>) => Promise<void>;
    onCancel: () => void;
    submitting?: boolean;
    errors?: Record<string, string[]>;
}

const emptyForm: CouponFormData = {
    code: '',
    type: 'percentage',
    value: '',
    valid_from: '',
    valid_until: '',
    max_uses: '',
    min_order_amount: '',
    first_order_only: false,
    valid_days: [],
    valid_hours_from: '',
    valid_hours_to: '',
    auto_apply: false,
};

const WEEKDAYS: { value: number; label: string }[] = [
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mié' },
    { value: 4, label: 'Jue' },
    { value: 5, label: 'Vie' },
    { value: 6, label: 'Sáb' },
    { value: 0, label: 'Dom' },
];

function couponToForm(coupon: Coupon): CouponFormData {
    return {
        code: coupon.code,
        type: coupon.type,
        value: String(coupon.value),
        valid_from: coupon.valid_from ? coupon.valid_from.slice(0, 16) : '',
        valid_until: coupon.valid_until ? coupon.valid_until.slice(0, 16) : '',
        max_uses: coupon.max_uses !== null ? String(coupon.max_uses) : '',
        min_order_amount: Number(coupon.min_order_amount) > 0 ? String(coupon.min_order_amount) : '',
        first_order_only: coupon.first_order_only,
        valid_days: Array.isArray(coupon.valid_days) ? coupon.valid_days : [],
        valid_hours_from: coupon.valid_hours_from ? coupon.valid_hours_from.slice(0, 5) : '',
        valid_hours_to: coupon.valid_hours_to ? coupon.valid_hours_to.slice(0, 5) : '',
        auto_apply: coupon.auto_apply ?? false,
    };
}

export function CouponForm({ coupon, onSubmit, onCancel, submitting = false, errors = {} }: CouponFormProps) {
    const [form, setForm] = useState<CouponFormData>(coupon ? couponToForm(coupon) : emptyForm);
    const [codeError, setCodeError] = useState<string | null>(null);
    const [dateError, setDateError] = useState<string | null>(null);
    const [hoursError, setHoursError] = useState<string | null>(null);
    const codeInputRef = useRef<HTMLInputElement>(null);
    const isEditing = Boolean(coupon);

    useEffect(() => {
        setForm(coupon ? couponToForm(coupon) : emptyForm);
    }, [coupon]);

    useEffect(() => {
        if (form.valid_from && form.valid_until) {
            if (new Date(form.valid_from) >= new Date(form.valid_until)) {
                setDateError('"Válido hasta" debe ser posterior a "Válido desde"');
            } else {
                setDateError(null);
            }
        } else {
            setDateError(null);
        }
    }, [form.valid_from, form.valid_until]);

    useEffect(() => {
        const a = form.valid_hours_from;
        const b = form.valid_hours_to;
        if ((a && !b) || (!a && b)) {
            setHoursError('Debes definir hora de inicio y hora de fin (o dejar ambas vacías).');
        } else {
            setHoursError(null);
        }
    }, [form.valid_hours_from, form.valid_hours_to]);

    function handleChange<K extends keyof CouponFormData>(field: K, value: CouponFormData[K]) {
        setForm((prev) => ({ ...prev, [field]: value }));
        if (field === 'code') setCodeError(null);
    }

    function toggleDay(day: number) {
        setForm((prev) => ({
            ...prev,
            valid_days: prev.valid_days.includes(day) ? prev.valid_days.filter((d) => d !== day) : [...prev.valid_days, day].sort((a, b) => a - b),
        }));
    }

    function handleGenerate() {
        const code = generateCouponCode();
        handleChange('code', code);
        setCodeError(null);
        setTimeout(() => codeInputRef.current?.focus(), 0);
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (dateError || hoursError) return;

        if (!isEditing) {
            const codeErr = validateCouponCode(form.code);
            if (codeErr) {
                setCodeError(codeErr);
                return;
            }
        }

        const payload: Partial<CouponFormData> = {
            type: form.type,
            value: form.value,
            valid_from: form.valid_from || undefined,
            valid_until: form.valid_until || undefined,
            max_uses: form.max_uses || undefined,
            min_order_amount: form.min_order_amount || undefined,
            first_order_only: form.first_order_only,
            valid_days: form.valid_days.length > 0 ? form.valid_days : undefined,
            valid_hours_from: form.valid_hours_from || undefined,
            valid_hours_to: form.valid_hours_to || undefined,
            auto_apply: form.auto_apply,
        };

        if (!isEditing) {
            payload.code = form.code.toUpperCase();
        }

        await onSubmit(payload);
    }

    function fieldError(field: string): string | undefined {
        return errors[field]?.[0];
    }

    const hasBlockingError = !!dateError || !!hoursError;

    return (
        <Dialog open onOpenChange={(o) => !o && onCancel()}>
            <DialogContent className="max-h-[90vh] overflow-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEditing ? 'Editar cupón' : 'Crear cupón'}</DialogTitle>
                </DialogHeader>

                <form noValidate onSubmit={handleSubmit} className="space-y-4">
                    {!isEditing && (
                        <div className="space-y-1.5">
                            <Label htmlFor="code">Código</Label>
                            <div className="flex gap-2">
                                <Input
                                    ref={codeInputRef}
                                    id="code"
                                    value={form.code}
                                    onChange={(e) => handleChange('code', e.target.value.toUpperCase())}
                                    placeholder="VERANO2024"
                                    maxLength={20}
                                    className="uppercase"
                                    disabled={submitting}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={handleGenerate}
                                    disabled={submitting}
                                    title="Generar código aleatorio"
                                    className="shrink-0 gap-1.5 text-xs"
                                >
                                    <RefreshCw className="h-3.5 w-3.5" />
                                    Generar
                                </Button>
                            </div>
                            {(codeError || fieldError('code')) && <p className="text-destructive text-xs">{codeError ?? fieldError('code')}</p>}
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="type">Tipo</Label>
                            <select
                                id="type"
                                value={form.type}
                                onChange={(e) => handleChange('type', e.target.value as CouponFormData['type'])}
                                disabled={submitting}
                                className="border-input bg-background focus:border-primary h-9 w-full rounded-md border px-3 text-sm focus:outline-none"
                            >
                                <option value="percentage">Porcentaje (%)</option>
                                <option value="fixed_amount">Monto fijo ($)</option>
                            </select>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="value">Valor {form.type === 'percentage' ? '(%)' : '($)'}</Label>
                            <Input
                                id="value"
                                type="number"
                                min="1"
                                step="1"
                                value={form.value}
                                onChange={(e) => handleChange('value', e.target.value)}
                                placeholder={form.type === 'percentage' ? '10' : '5000'}
                                disabled={submitting}
                            />
                            {fieldError('value') && <p className="text-destructive text-xs">{fieldError('value')}</p>}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="valid_from">Válido desde</Label>
                            <Input
                                id="valid_from"
                                type="datetime-local"
                                value={form.valid_from}
                                onChange={(e) => handleChange('valid_from', e.target.value)}
                                disabled={submitting}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="valid_until">Válido hasta</Label>
                            <Input
                                id="valid_until"
                                type="datetime-local"
                                value={form.valid_until}
                                onChange={(e) => handleChange('valid_until', e.target.value)}
                                className={cn(dateError && 'border-destructive focus-visible:ring-destructive/40')}
                                disabled={submitting}
                            />
                        </div>
                    </div>

                    {dateError && <p className="text-destructive text-xs">{dateError}</p>}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="max_uses">Usos máximos</Label>
                            <Input
                                id="max_uses"
                                type="number"
                                min="1"
                                value={form.max_uses}
                                onChange={(e) => handleChange('max_uses', e.target.value)}
                                placeholder="Sin límite"
                                disabled={submitting}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="min_order_amount">Monto mínimo ($)</Label>
                            <Input
                                id="min_order_amount"
                                type="number"
                                min="0"
                                value={form.min_order_amount}
                                onChange={(e) => handleChange('min_order_amount', e.target.value)}
                                placeholder="0"
                                disabled={submitting}
                            />
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <input
                            id="first_order_only"
                            type="checkbox"
                            checked={form.first_order_only}
                            onChange={(e) => handleChange('first_order_only', e.target.checked)}
                            disabled={submitting}
                            className="border-input accent-primary h-4 w-4 rounded"
                        />
                        <Label htmlFor="first_order_only" className="cursor-pointer">
                            Solo para primer pedido del cliente
                        </Label>
                    </div>

                    <div className="border-border bg-muted/40 rounded-lg border p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-foreground text-sm font-semibold">Programación (happy hour)</h3>
                            <span className="text-muted-foreground text-[10px] tracking-wide uppercase">Opcional</span>
                        </div>

                        <div className="space-y-3">
                            <div>
                                <Label className="text-xs">Días de la semana</Label>
                                <p className="text-muted-foreground mt-0.5 text-[11px]">Si no marcas ninguno, aplica todos los días.</p>
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {WEEKDAYS.map((d) => {
                                        const active = form.valid_days.includes(d.value);
                                        return (
                                            <button
                                                key={d.value}
                                                type="button"
                                                onClick={() => toggleDay(d.value)}
                                                disabled={submitting}
                                                className={cn(
                                                    'focus:ring-ring min-w-[44px] rounded-full border px-3 py-1 text-xs font-medium transition-colors focus:ring-2 focus:outline-none disabled:opacity-50',
                                                    active
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-border bg-background hover:bg-muted',
                                                )}
                                            >
                                                {d.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="valid_hours_from" className="text-xs">
                                        Desde
                                    </Label>
                                    <Input
                                        id="valid_hours_from"
                                        type="time"
                                        value={form.valid_hours_from}
                                        onChange={(e) => handleChange('valid_hours_from', e.target.value)}
                                        className={cn(hoursError && 'border-destructive focus-visible:ring-destructive/40')}
                                        disabled={submitting}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="valid_hours_to" className="text-xs">
                                        Hasta
                                    </Label>
                                    <Input
                                        id="valid_hours_to"
                                        type="time"
                                        value={form.valid_hours_to}
                                        onChange={(e) => handleChange('valid_hours_to', e.target.value)}
                                        className={cn(hoursError && 'border-destructive focus-visible:ring-destructive/40')}
                                        disabled={submitting}
                                    />
                                </div>
                            </div>
                            {hoursError && <p className="text-destructive text-xs">{hoursError}</p>}
                            <p className="text-muted-foreground text-[11px]">
                                Horario en zona América/Bogotá. Si la hora final es menor a la inicial, se entiende como ventana que cruza medianoche
                                (ej. 22:00 → 02:00).
                            </p>

                            <div className="flex items-center gap-3 pt-1">
                                <input
                                    id="auto_apply"
                                    type="checkbox"
                                    checked={form.auto_apply}
                                    onChange={(e) => handleChange('auto_apply', e.target.checked)}
                                    disabled={submitting}
                                    className="border-input accent-primary h-4 w-4 rounded"
                                />
                                <Label htmlFor="auto_apply" className="cursor-pointer">
                                    Aplicar automáticamente al carrito (sin pedir código)
                                </Label>
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onCancel} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting || hasBlockingError}>
                            {submitting ? (
                                <span className="flex items-center gap-2">
                                    <LoaderCircle className="h-4 w-4 animate-spin" />
                                    Guardando…
                                </span>
                            ) : isEditing ? (
                                'Guardar cambios'
                            ) : (
                                'Crear cupón'
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
