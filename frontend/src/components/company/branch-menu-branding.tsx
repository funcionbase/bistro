import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useToast } from '@/components/ui/toast';
import { apiFetch } from '@/lib/api';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { Info, ImageOff, Loader2, Trash2, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

// ponytail: union covers all pending states for an image slot
type PendingImg = { file: File; previewUrl: string } | 'delete' | null;

interface BranchSettings {
    menu_header_image_url: string | null;
    menu_footer_image_url: string | null;
    menu_tagline: string | null;
    menu_card_style: 'default' | 'compact' | 'card';
    menu_show_branding: boolean;
}

interface BranchMenuBrandingProps {
    branchId: string;
}

export function BranchMenuBranding({ branchId }: BranchMenuBrandingProps) {
    const { showToast } = useToast();
    const [settings, setSettings] = useState<BranchSettings | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [pendingHeader, setPendingHeader] = useState<PendingImg>(null);
    const [pendingFooter, setPendingFooter] = useState<PendingImg>(null);
    const [showTechTip, setShowTechTip] = useState(false);
    const headerInputRef = useRef<HTMLInputElement>(null);
    const footerInputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        setLoading(true);
        apiFetch(`/api/v1/company/branches/${branchId}/settings`)
            .then((r) => r.json())
            .then((data: { settings: BranchSettings }) => setSettings(data.settings))
            .catch(() => showToast('error', 'Error cargando configuración de branding'))
            .finally(() => setLoading(false));
    }, [branchId, showToast]);

    const setPendingImage = (position: 'header' | 'footer', value: PendingImg) => {
        const prev = position === 'header' ? pendingHeader : pendingFooter;
        if (prev && prev !== 'delete') URL.revokeObjectURL(prev.previewUrl);
        if (position === 'header') setPendingHeader(value);
        else setPendingFooter(value);
    };

    const effectiveUrl = (position: 'header' | 'footer'): string | null => {
        const pending = position === 'header' ? pendingHeader : pendingFooter;
        if (pending === 'delete') return null;
        if (pending) return pending.previewUrl;
        return position === 'header'
            ? (settings?.menu_header_image_url ?? null)
            : (settings?.menu_footer_image_url ?? null);
    };

    const saveAll = async () => {
        if (!settings) return;
        setSaving(true);
        try {
            for (const [position, pending] of [['header', pendingHeader], ['footer', pendingFooter]] as const) {
                if (pending === 'delete') {
                    await apiFetch(`/api/v1/company/branches/${branchId}/settings/menu-${position}-image`, { method: 'DELETE' });
                } else if (pending) {
                    const formData = new FormData();
                    formData.append('image', pending.file);
                    await apiFetch(`/api/v1/company/branches/${branchId}/settings/menu-${position}-image`, {
                        method: 'POST',
                        body: formData,
                    });
                }
            }

            const res = await apiFetch(`/api/v1/company/branches/${branchId}/settings`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    menu_tagline: settings.menu_tagline?.trim() || null,
                    menu_card_style: settings.menu_card_style,
                    menu_show_branding: settings.menu_show_branding,
                }),
            });
            const data = (await res.json()) as { settings: BranchSettings };
            setSettings(data.settings);

            if (pendingHeader && pendingHeader !== 'delete') URL.revokeObjectURL(pendingHeader.previewUrl);
            if (pendingFooter && pendingFooter !== 'delete') URL.revokeObjectURL(pendingFooter.previewUrl);
            setPendingHeader(null);
            setPendingFooter(null);

            showToast('success', 'Configuración guardada');
        } catch {
            showToast('error', 'Error guardando configuración');
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return <div className="text-muted-foreground py-4 text-sm">Cargando configuración…</div>;
    }

    if (!settings) return null;

    return (
        <div className="space-y-6">
            <p className="text-muted-foreground text-xs">
                Personaliza cómo se ve la carta digital de esta sede. Los cambios aplican solo a esta sede.
            </p>

            {/* Imágenes decorativas */}
            <div className="grid gap-4 sm:grid-cols-2">
                <BannerImageField
                    label="Imagen superior (header)"
                    description="Aparece antes del listado de platos. Recomendado: 1200 × 350 px."
                    url={effectiveUrl('header')}
                    disabled={saving}
                    inputRef={headerInputRef}
                    onFileSelect={(f) => setPendingImage('header', { file: f, previewUrl: URL.createObjectURL(f) })}
                    onDelete={() => setPendingImage('header', 'delete')}
                />
                <BannerImageField
                    label="Imagen inferior (footer)"
                    description="Aparece al final del menú. Recomendado: 1200 × 290 px."
                    url={effectiveUrl('footer')}
                    disabled={saving}
                    inputRef={footerInputRef}
                    onFileSelect={(f) => setPendingImage('footer', { file: f, previewUrl: URL.createObjectURL(f) })}
                    onDelete={() => setPendingImage('footer', 'delete')}
                />
            </div>

            <Alert>
                <AlertDescription className="space-y-1.5 text-xs">
                    <p>
                        Sube cualquier foto de tu restaurante · máx 5 MB.
                        La imagen ocupará todo el ancho del menú sin marcos ni bordes blancos.
                    </p>
                    <p>
                        Para que se vea bien sin recortes, usa una foto <strong>ancha y horizontal</strong> (tipo panorámica).
                        Si subes una foto cuadrada o vertical, el sistema la ajusta automáticamente recortando lo que sobra arriba y abajo — el centro siempre queda visible.
                    </p>
                    <TooltipProvider>
                        <Tooltip open={showTechTip} onOpenChange={setShowTechTip}>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    className="text-muted-foreground flex cursor-pointer items-center gap-1 underline underline-offset-2"
                                    onClick={() => setShowTechTip((v) => !v)}
                                >
                                    <Info className="size-3" />
                                    Ver especificaciones técnicas, si las necesitas
                                </button>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-56 space-y-0.5 text-xs">
                                <p>Formatos: JPG, PNG o WebP</p>
                                <p>Header: 1200 × 350 px (relación 3:1)</p>
                                <p>Footer: 1200 × 290 px (relación 4:1)</p>
                                <p>Renderizado: object-cover centrado</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </AlertDescription>
            </Alert>

            {/* Tagline */}
            <div className="space-y-1.5">
                <Label htmlFor={`tagline-${branchId}`}>Frase bajo el nombre (tagline)</Label>
                <Input
                    id={`tagline-${branchId}`}
                    placeholder="Ej: Café, arte y literatura"
                    maxLength={120}
                    value={settings.menu_tagline ?? ''}
                    onChange={(e) =>
                        setSettings({ ...settings, menu_tagline: sanitizePlainText(e.target.value, 120, true, false) || null })
                    }
                />
                <p className="text-muted-foreground text-[11px]">{(settings.menu_tagline?.length ?? 0)}/120</p>
            </div>

            {/* Estilo de tarjeta */}
            <div className="space-y-1.5">
                <Label htmlFor={`card-style-${branchId}`}>Estilo visual de los platos</Label>
                <Select
                    value={settings.menu_card_style}
                    onValueChange={(v) =>
                        setSettings({ ...settings, menu_card_style: v as BranchSettings['menu_card_style'] })
                    }
                >
                    <SelectTrigger id={`card-style-${branchId}`}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="default">Por defecto — imagen pequeña a la derecha</SelectItem>
                        <SelectItem value="compact">Compacto — solo nombre y precio</SelectItem>
                        <SelectItem value="card">Card — imagen grande arriba</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            {/* Mostrar branding */}
            <div className="flex items-center justify-between gap-4 rounded-xl border p-4">
                <div>
                    <p className="text-sm font-medium">Mostrar "Carta dinámica · flexyflow"</p>
                    <p className="text-muted-foreground text-xs">Pie de página al final del menú público</p>
                </div>
                <Badge
                    variant={settings.menu_show_branding ? 'default' : 'secondary'}
                    className="cursor-pointer select-none"
                    onClick={() => setSettings({ ...settings, menu_show_branding: !settings.menu_show_branding })}
                >
                    {settings.menu_show_branding ? 'Visible' : 'Oculto'}
                </Badge>
            </div>

            <div className="flex justify-end">
                <Button onClick={saveAll} disabled={saving}>
                    {saving && <Loader2 className="mr-2 size-4 animate-spin" />}
                    Guardar
                </Button>
            </div>
        </div>
    );
}

interface BannerImageFieldProps {
    label: string;
    description: string;
    url: string | null;
    disabled: boolean;
    inputRef: React.RefObject<HTMLInputElement | null>;
    onFileSelect: (f: File) => void;
    onDelete: () => void;
}

function BannerImageField({ label, description, url, disabled, inputRef, onFileSelect, onDelete }: BannerImageFieldProps) {
    return (
        <div className="space-y-2">
            <Label className="text-sm font-medium">{label}</Label>
            <p className="text-muted-foreground text-[11px]">{description}</p>
            <div className="bg-muted relative h-28 overflow-hidden rounded-xl border">
                {url ? (
                    <img src={url} alt={label} className="h-full w-full object-cover" />
                ) : (
                    <div className="text-muted-foreground flex h-full flex-col items-center justify-center gap-1">
                        <ImageOff className="size-6" />
                        <span className="text-xs">Sin imagen</span>
                    </div>
                )}
            </div>
            <div className="flex gap-2">
                <input
                    ref={inputRef}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="hidden"
                    onChange={(e) => {
                        const f = e.target.files?.[0];
                        if (f) onFileSelect(f);
                        e.target.value = '';
                    }}
                />
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={disabled}
                    onClick={() => inputRef.current?.click()}
                    className="flex-1"
                >
                    <Upload className="mr-1.5 size-3.5" />
                    {url ? 'Cambiar' : 'Subir'}
                </Button>
                {url && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={disabled}
                        onClick={onDelete}
                    >
                        <Trash2 className="size-3.5" />
                    </Button>
                )}
            </div>
        </div>
    );
}
