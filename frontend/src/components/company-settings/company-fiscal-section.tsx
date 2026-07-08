import { useEffect, useState } from 'react';

import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SanitizedInput } from '@/components/ui/sanitized-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { DianApiError, getFiscalProfile, updateFiscalProfile } from '@/lib/dian-api';
import type { DianDocTypeCode, DianFiscalProfile, DianFiscalProfileResponse } from '@/types/dian';

/**
 * Sección "Datos fiscales (facturación electrónica)" dentro de
 * /company/settings → Información.
 *
 * Antes vivía como la pestaña "Perfil fiscal" de /company/dian (owner-only).
 * Se movió aquí para que la identidad fiscal del emisor (representante legal,
 * actividad económica, responsabilidades DIAN, municipio, contacto de
 * facturación, dirección) se complete junto con el resto de los datos de la
 * empresa. El endpoint (`GET|PUT /api/v1/dian/fiscal-profile`) ahora se gatea
 * con `company.update` igual que el resto de Settings.
 *
 * Es autocontenida: tiene su propio fetch/guardado y su propio botón "Guardar",
 * independiente del formulario principal (que es multipart por el logo/QR).
 *
 * Permisos: el gate es `company.fiscal_profile`. Si el rol no puede leerla
 * (403), la sección se oculta. La edición se habilita según `can_update` que
 * devuelve el backend (los roles de sistema bypassean; los operativos requieren
 * el permiso explícito).
 */
export function CompanyFiscalSection() {
    const [data, setData] = useState<DianFiscalProfileResponse | null>(null);
    const [form, setForm] = useState<Partial<DianFiscalProfile>>({});
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // Errores 422 por campo → inline bajo cada input. `error` queda para el
    // fallo no atribuible a un campo.
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [okMsg, setOkMsg] = useState<string | null>(null);
    const [loadFailed, setLoadFailed] = useState(false);

    useEffect(() => {
        getFiscalProfile()
            .then((res) => {
                setData(res);
                setForm(res.data);
            })
            .catch(() => {
                // Sin permiso de lectura (403) u otro fallo de carga: ocultamos
                // la sección en vez de romper la pantalla de Settings.
                setLoadFailed(true);
            });
    }, []);

    if (loadFailed) {
        return null;
    }

    if (data === null) {
        return <Skeleton className="h-96 w-full rounded-2xl md:col-span-2" />;
    }

    const canEdit = data.can_update;
    const fiscalCatalog = data.catalogs.fiscal_responsibilities;
    const docCatalog = data.catalogs.doc_types;
    const responsibilities = new Set(form.fiscal_responsibilities ?? []);

    const toggleResp = (slug: string, checked: boolean) => {
        const next = new Set(responsibilities);
        if (checked) {
            next.add(slug);
        } else {
            next.delete(slug);
        }
        setForm({ ...form, fiscal_responsibilities: Array.from(next) });
    };

    const handleSave = async () => {
        setSaving(true);
        setError(null);
        setFieldErrors({});
        setOkMsg(null);
        try {
            const res = await updateFiscalProfile(form);
            setData(res);
            setForm(res.data);
            setOkMsg('Datos fiscales guardados.');
        } catch (e) {
            // 422 con errores por campo → inline bajo cada input; si no, mensaje general.
            if (e instanceof DianApiError && e.errors) {
                const mapped: Record<string, string> = {};
                for (const [field, messages] of Object.entries(e.errors)) {
                    mapped[field] = messages[0] ?? '';
                }
                setFieldErrors(mapped);
            } else {
                setError(e instanceof Error ? e.message : 'Error al guardar');
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <Card className="space-y-4 p-4 md:col-span-2">
            <div>
                <h3 className="text-sm font-semibold text-foreground">Datos fiscales (facturación electrónica)</h3>
                <p className="text-xs text-muted-foreground">
                    Identidad del emisor ante la DIAN. Se usa al emitir facturas electrónicas.
                </p>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <Label>NIT</Label>
                    <Input value={data.data.nit} disabled />
                </div>
                <div>
                    <Label htmlFor="dv">Dígito verificación</Label>
                    <Input
                        id="dv"
                        value={form.dv ?? ''}
                        onChange={(e) => setForm({ ...form, dv: e.target.value.replace(/\D/g, '').slice(0, 1) })}
                        maxLength={1}
                        disabled={!canEdit}
                        aria-invalid={!!fieldErrors.dv}
                    />
                    <InputError message={fieldErrors.dv} className="text-xs" />
                </div>
                <div>
                    <Label htmlFor="repName">Representante legal</Label>
                    <SanitizedInput
                        id="repName"
                        value={form.legal_representative_name ?? ''}
                        onChange={(v) => setForm({ ...form, legal_representative_name: v })}
                        maxLength={200}
                        allowWhitespace
                        disabled={!canEdit}
                    />
                    <InputError message={fieldErrors.legal_representative_name} className="text-xs" />
                </div>
                <div className="grid grid-cols-3 gap-2">
                    <div>
                        <Label>Tipo de documento</Label>
                        <Select
                            value={form.legal_representative_doc_type ?? 'CC'}
                            onValueChange={(v) =>
                                setForm({ ...form, legal_representative_doc_type: v as DianDocTypeCode })
                            }
                            disabled={!canEdit}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(docCatalog).map(([k, l]) => (
                                    <SelectItem key={k} value={k}>
                                        {k} — {l}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="col-span-2">
                        <Label>Documento</Label>
                        <SanitizedInput
                            value={form.legal_representative_doc_number ?? ''}
                            onChange={(v) => setForm({ ...form, legal_representative_doc_number: v })}
                            maxLength={30}
                            disabled={!canEdit}
                        />
                        <InputError message={fieldErrors.legal_representative_doc_number} className="text-xs" />
                    </div>
                </div>
                <div>
                    <Label htmlFor="ciiu">Actividad económica (CIIU)</Label>
                    <Input
                        id="ciiu"
                        value={form.economic_activity_code ?? ''}
                        onChange={(e) =>
                            setForm({ ...form, economic_activity_code: e.target.value.replace(/\D/g, '').slice(0, 4) })
                        }
                        maxLength={4}
                        placeholder="5611"
                        disabled={!canEdit}
                        aria-invalid={!!fieldErrors.economic_activity_code}
                    />
                    <InputError message={fieldErrors.economic_activity_code} className="text-xs" />
                </div>
                <div>
                    <Label htmlFor="dane">Municipio DANE (5 dígitos)</Label>
                    <Input
                        id="dane"
                        value={form.municipality_dane_code ?? ''}
                        onChange={(e) =>
                            setForm({ ...form, municipality_dane_code: e.target.value.replace(/\D/g, '').slice(0, 5) })
                        }
                        maxLength={5}
                        placeholder="66001"
                        disabled={!canEdit}
                        aria-invalid={!!fieldErrors.municipality_dane_code}
                    />
                    <InputError message={fieldErrors.municipality_dane_code} className="text-xs" />
                </div>
                <div>
                    <Label htmlFor="billingEmail">Correo facturación</Label>
                    <Input
                        id="billingEmail"
                        type="email"
                        value={form.billing_email ?? ''}
                        onChange={(e) => setForm({ ...form, billing_email: e.target.value })}
                        disabled={!canEdit}
                        aria-invalid={!!fieldErrors.billing_email}
                    />
                    <InputError message={fieldErrors.billing_email} className="text-xs" />
                </div>
                <div>
                    <Label htmlFor="billingPhone">Teléfono facturación</Label>
                    <Input
                        id="billingPhone"
                        value={form.billing_phone ?? ''}
                        onChange={(e) => setForm({ ...form, billing_phone: e.target.value })}
                        disabled={!canEdit}
                        aria-invalid={!!fieldErrors.billing_phone}
                    />
                    <InputError message={fieldErrors.billing_phone} className="text-xs" />
                </div>
                <div className="md:col-span-2">
                    <Label htmlFor="addr">Dirección física</Label>
                    <SanitizedInput
                        id="addr"
                        value={form.physical_address ?? ''}
                        onChange={(v) => setForm({ ...form, physical_address: v })}
                        maxLength={255}
                        allowWhitespace
                        disabled={!canEdit}
                    />
                    <InputError message={fieldErrors.physical_address} className="text-xs" />
                </div>
            </div>

            <div>
                <Label>Responsabilidades fiscales DIAN</Label>
                <div className="mt-1 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    {Object.entries(fiscalCatalog).map(([slug, label]) => (
                        <label key={slug} className="flex cursor-pointer items-center gap-2">
                            <Checkbox
                                checked={responsibilities.has(slug)}
                                onCheckedChange={(checked) => toggleResp(slug, Boolean(checked))}
                                disabled={!canEdit}
                            />
                            <span>
                                <span className="font-mono text-xs">{slug}</span> — {label}
                            </span>
                        </label>
                    ))}
                </div>
            </div>

            {error && (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}
            {okMsg && (
                <Alert variant="safe">
                    <AlertDescription>{okMsg}</AlertDescription>
                </Alert>
            )}

            {canEdit && (
                <div className="flex justify-end">
                    <Button onClick={handleSave} disabled={saving}>
                        {saving ? 'Guardando...' : 'Guardar datos fiscales'}
                    </Button>
                </div>
            )}
        </Card>
    );
}
