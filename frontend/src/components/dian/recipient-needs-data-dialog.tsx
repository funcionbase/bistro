import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SanitizedInput } from '@/components/ui/sanitized-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { updateContactDianProfile } from '@/lib/dian-api';
import type { DianDocTypeCode } from '@/types/dian';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    contactId: string;
    initial: {
        name?: string | null;
        doc_type?: DianDocTypeCode | null;
        doc_number?: string | null;
        dv?: string | null;
        legal_name?: string | null;
        email?: string | null;
        address?: string | null;
    };
    docTypeCatalog: Record<string, string>;
    fiscalResponsibilitiesCatalog: Record<string, string>;
    onSaved: () => void;
}

/**
 * Modal "Faltan datos del contacto para DIAN".
 *
 * Aparece cuando el lookup por phone encuentra un Contact con perfil DIAN
 * incompleto. El cajero captura los mínimos y al guardar se marca
 * `dian_profile_completed_at` para que el siguiente intento de emisión use
 * directo el Contact sin volver a pedir.
 */
export function RecipientNeedsDataDialog({
    open,
    onOpenChange,
    contactId,
    initial,
    docTypeCatalog,
    fiscalResponsibilitiesCatalog,
    onSaved,
}: Props) {
    const [docType, setDocType] = useState<DianDocTypeCode>((initial.doc_type as DianDocTypeCode) ?? 'CC');
    const [docNumber, setDocNumber] = useState(initial.doc_number ?? '');
    const [dv, setDv] = useState(initial.dv ?? '');
    const [legalName, setLegalName] = useState(initial.legal_name ?? initial.name ?? '');
    const [email, setEmail] = useState(initial.email ?? '');
    const [address, setAddress] = useState(initial.address ?? '');
    const [responsibilities, setResponsibilities] = useState<Set<string>>(new Set(['R-99-PN']));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const toggleResp = (slug: string, checked: boolean) => {
        const next = new Set(responsibilities);
        if (checked) {
            next.add(slug);
        } else {
            next.delete(slug);
        }
        setResponsibilities(next);
    };

    const handleSave = async () => {
        setSaving(true);
        setError(null);
        try {
            await updateContactDianProfile(contactId, {
                doc_type: docType,
                doc_number: docNumber,
                dv: docType === 'NIT' ? dv || null : null,
                legal_name: legalName,
                email: email || null,
                address: address || null,
                fiscal_responsibilities: Array.from(responsibilities),
            });
            onSaved();
            onOpenChange(false);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'No se pudieron guardar los datos.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Faltan datos del contacto para DIAN</DialogTitle>
                    <DialogDescription>
                        Para emitir la factura electrónica necesitamos el documento, razón social y datos de contacto.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    <div className="grid grid-cols-3 gap-2">
                        <div className="col-span-1">
                            <Label htmlFor="docType">Tipo doc</Label>
                            <Select value={docType} onValueChange={(v) => setDocType(v as DianDocTypeCode)}>
                                <SelectTrigger id="docType">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(docTypeCatalog).map(([key, label]) => (
                                        <SelectItem key={key} value={key}>
                                            {key} — {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className={docType === 'NIT' ? 'col-span-1' : 'col-span-2'}>
                            <Label htmlFor="docNumber">Número</Label>
                            <SanitizedInput id="docNumber" value={docNumber} onChange={setDocNumber} maxLength={30} />
                        </div>
                        {docType === 'NIT' && (
                            <div className="col-span-1">
                                <Label htmlFor="dv">DV</Label>
                                <Input
                                    id="dv"
                                    value={dv}
                                    onChange={(e) => setDv(e.target.value.replace(/\D/g, '').slice(0, 1))}
                                    maxLength={1}
                                />
                            </div>
                        )}
                    </div>

                    <div>
                        <Label htmlFor="legalName">Razón social / nombre completo</Label>
                        <SanitizedInput id="legalName" value={legalName} onChange={setLegalName} maxLength={200} allowWhitespace />
                    </div>

                    <div className="grid grid-cols-2 gap-2">
                        <div>
                            <Label htmlFor="email">Correo</Label>
                            <Input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={200} />
                        </div>
                        <div>
                            <Label htmlFor="address">Dirección</Label>
                            <SanitizedInput id="address" value={address} onChange={setAddress} maxLength={255} allowWhitespace />
                        </div>
                    </div>

                    <div>
                        <Label>Responsabilidades fiscales</Label>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-1 text-sm">
                            {Object.entries(fiscalResponsibilitiesCatalog).map(([slug, label]) => (
                                <label key={slug} className="flex items-center gap-2 cursor-pointer">
                                    <Checkbox
                                        checked={responsibilities.has(slug)}
                                        onCheckedChange={(checked) => toggleResp(slug, Boolean(checked))}
                                    />
                                    <span>
                                        <span className="font-mono text-xs">{slug}</span> — {label}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </div>

                    {error && <p className="text-sm text-[color:var(--color-status-critical)]">{error}</p>}
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={saving}>
                        Cancelar
                    </Button>
                    <Button onClick={handleSave} disabled={saving || !docNumber || !legalName}>
                        {saving ? 'Guardando...' : 'Guardar y emitir'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
