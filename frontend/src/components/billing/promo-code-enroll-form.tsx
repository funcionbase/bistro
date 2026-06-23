import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type PromoEnrollPreview } from '@/hooks/use-company-promo-code';
import { formatCOP } from '@/lib/formatters';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { AlertCircle, BadgePercent, CheckCircle2, Loader2 } from 'lucide-react';
import { useState } from 'react';

interface PromoCodeEnrollFormProps {
    onPreview: (code: string) => Promise<{ data: PromoEnrollPreview | null; errorCode: string | null; message: string | null }>;
    onApply: (code: string) => Promise<{ ok: boolean; errorCode: string | null; message: string | null }>;
}

const MAX_BYTES = 50;

/**
 * Form de inscripción self-service de promo codes desde billing-tab — #246.
 *
 * Flujo: input + "Validar código" → muestra preview con starts_at_preview +
 * ahorro mensual → botón "Confirmar inscripción" → POST + refetch del
 * activo (lo asume el caller via hook).
 */
export function PromoCodeEnrollForm({ onPreview, onApply }: PromoCodeEnrollFormProps) {
    const [code, setCode] = useState('');
    const [preview, setPreview] = useState<PromoEnrollPreview | null>(null);
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [loadingApply, setLoadingApply] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const formatDateLong = (iso: string): string => {
        try {
            return new Date(iso).toLocaleDateString('es-CO', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
                timeZone: 'America/Bogota',
            });
        } catch {
            return iso;
        }
    };

    const handlePreview = async (e: React.FormEvent) => {
        e.preventDefault();
        setErrorMessage(null);
        setPreview(null);
        const cleanCode = sanitizePlainText(code, MAX_BYTES, false).toUpperCase();
        if (cleanCode.length < 2) {
            setErrorMessage('Ingresa un código válido.');
            return;
        }
        setLoadingPreview(true);
        const result = await onPreview(cleanCode);
        setLoadingPreview(false);
        if (result.data === null) {
            setErrorMessage(result.message ?? 'No se pudo validar el código.');
            return;
        }
        setPreview(result.data);
    };

    const handleApply = async () => {
        if (preview === null) return;
        setErrorMessage(null);
        setLoadingApply(true);
        const result = await onApply(preview.code);
        setLoadingApply(false);
        if (!result.ok) {
            setErrorMessage(result.message ?? 'No se pudo aplicar el código.');
            return;
        }
        // El refetch del activo lo hace el hook; el form se desmonta porque
        // el padre cambia a renderizar <ActivePromoCodeCard>.
        setCode('');
        setPreview(null);
    };

    return (
        <DashboardPanel title="Inscribir un código promocional" icon={BadgePercent}>
            <form noValidate className="space-y-4" onSubmit={handlePreview}>
                <div className="grid gap-2">
                    <Label htmlFor="promo-code">Código</Label>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Input
                            id="promo-code"
                            type="text"
                            value={code}
                            onChange={(e) => setCode(sanitizePlainText(e.target.value, MAX_BYTES, false))}
                            placeholder="Ej: BLACKFRIDAY2026"
                            maxLength={MAX_BYTES}
                            autoComplete="off"
                            className="uppercase sm:flex-1"
                        />
                        <Button type="submit" variant="outline" disabled={loadingPreview || code.length < 2}>
                            {loadingPreview && <Loader2 className="mr-2 h-3 w-3 animate-spin" />}
                            Validar código
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        El descuento empieza el primer día del próximo mes. No afecta las facturas ya emitidas.
                    </p>
                </div>

                {errorMessage !== null && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{errorMessage}</AlertDescription>
                    </Alert>
                )}

                {preview !== null && (
                    <div className="bg-muted/50 border-border space-y-4 rounded-2xl border p-4">
                        <div className="flex items-start gap-3">
                            <CheckCircle2 className="text-success-foreground mt-0.5 h-5 w-5 shrink-0" />
                            <div>
                                <p className="text-foreground text-sm font-medium">
                                    {preview.name} — {preview.discount_percent}% por {preview.months_duration} meses
                                </p>
                                {preview.description !== null && (
                                    <p className="text-muted-foreground mt-1 text-xs">{preview.description}</p>
                                )}
                            </div>
                        </div>

                        <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Inicia</dt>
                                <dd className="text-foreground mt-1">{formatDateLong(preview.starts_at_preview)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Termina</dt>
                                <dd className="text-foreground mt-1">{formatDateLong(preview.ends_at_preview)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-[11px] uppercase tracking-[0.15em]">Ahorro mensual</dt>
                                <dd className="text-foreground font-brand mt-1 text-xl tabular-nums">${formatCOP(preview.monthly_savings)}</dd>
                            </div>
                        </dl>

                        <Button type="button" onClick={handleApply} disabled={loadingApply} className="w-full sm:w-auto">
                            {loadingApply && <Loader2 className="mr-2 h-3 w-3 animate-spin" />}
                            Confirmar inscripción
                        </Button>
                    </div>
                )}
            </form>
        </DashboardPanel>
    );
}
