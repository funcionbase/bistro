import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { apiFetch } from '@/lib/api';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { isValidColombianMobile, stripCountryPrefix } from '@/lib/phone';
import { Building2, LoaderCircle, User, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';

interface NewClientDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (client: CreatedClient) => void;
    /**
     * Teléfono con el que pre-llenar el campo al abrir (ej. el que el cajero ya
     * tecleó en el flujo de cobro DIAN). Opcional.
     */
    initialPhone?: string;
}

export type ContactKind = 'natural' | 'company';

export interface CreatedClient {
    id: string;
    phone: string | null;
    name: string;
    kind: ContactKind;
    doc_type: string;
    doc_number: string;
    legal_name: string | null;
    email: string | null;
    notes: string | null;
    branch_id: string;
    created_at: string | null;
}

interface FormState {
    kind: ContactKind;
    doc_type: string;
    doc_number: string;
    dv: string;
    phone: string;
    name: string;
    legal_name: string;
    email: string;
    notes: string;
}

const INITIAL_FORM: FormState = {
    kind: 'natural',
    doc_type: 'CC',
    doc_number: '',
    dv: '',
    phone: '',
    name: '',
    legal_name: '',
    email: '',
    notes: '',
};

const DOC_TYPES_NATURAL = [
    { value: 'CC', label: 'Cédula de ciudadanía' },
    { value: 'CE', label: 'Cédula de extranjería' },
    { value: 'TI', label: 'Tarjeta de identidad' },
    { value: 'PA', label: 'Pasaporte' },
    { value: 'RC', label: 'Registro civil' },
];

const DOC_TYPES_COMPANY = [
    { value: 'NIT', label: 'NIT' },
    { value: 'NIT_EXT', label: 'NIT extranjero' },
];

const DEFAULT_DOC_BY_KIND: Record<ContactKind, string> = {
    natural: 'CC',
    company: 'NIT',
};

/**
 * Diálogo del DS para registrar un contacto (persona natural o empresa) desde
 * el CRM.
 *
 * Refactor #235: la identidad canónica es (company_nit, doc_number). El
 * selector "Persona natural / Empresa" filtra el catálogo de doc_type
 * (impide estados imposibles tipo "persona con NIT"). Razón social es
 * obligatoria solo para empresas. Phone es opcional siempre.
 */
export function NewClientDialog({ open, onOpenChange, onCreated, initialPhone }: NewClientDialogProps) {
    const [form, setForm] = useState<FormState>(INITIAL_FORM);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [topError, setTopError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    // Pre-llena el teléfono al abrir cuando el caller lo provee (flujo de cobro
    // de mesa: el cajero ya tecleó el teléfono al buscar el contacto DIAN).
    useEffect(() => {
        if (open && initialPhone) {
            setForm((prev) => ({ ...prev, phone: stripCountryPrefix(initialPhone) }));
        }
    }, [open, initialPhone]);

    const isCompany = form.kind === 'company';
    const docTypeOptions = isCompany ? DOC_TYPES_COMPANY : DOC_TYPES_NATURAL;

    function resetAndClose() {
        if (submitting) return;
        setForm(INITIAL_FORM);
        setErrors({});
        setTopError(null);
        onOpenChange(false);
    }

    function setField<K extends keyof FormState>(key: K, value: FormState[K]) {
        setForm((prev) => ({ ...prev, [key]: value }));
        setErrors((prev) => {
            if (!(key in prev)) return prev;
            const next = { ...prev };
            delete next[key];
            return next;
        });
        setTopError(null);
    }

    function setKind(kind: ContactKind) {
        // Al cambiar de naturaleza, re-elegimos un doc_type válido en el
        // nuevo catálogo y limpiamos legal_name + dv si no aplican.
        setForm((prev) => ({
            ...prev,
            kind,
            doc_type: DEFAULT_DOC_BY_KIND[kind],
            dv: kind === 'company' ? prev.dv : '',
            legal_name: kind === 'company' ? prev.legal_name : '',
        }));
        setErrors({});
        setTopError(null);
    }

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (submitting) return;

        const trimmedName = form.name.trim();
        const trimmedDoc = form.doc_number.trim().toUpperCase();
        const trimmedLegalName = form.legal_name.trim();
        const trimmedEmail = form.email.trim();
        const trimmedNotes = form.notes.trim();
        const phoneForApi = stripCountryPrefix(form.phone);

        const localErrors: Record<string, string> = {};
        if (trimmedName === '') {
            localErrors.name = isCompany ? 'El nombre comercial es obligatorio.' : 'El nombre completo es obligatorio.';
        }
        if (trimmedDoc === '') {
            localErrors.doc_number = 'El número de documento es obligatorio.';
        } else if (!/^[A-Z0-9-]+$/.test(trimmedDoc)) {
            localErrors.doc_number = 'Solo letras, números y guiones.';
        }
        if (form.dv !== '' && !/^\d$/.test(form.dv)) {
            localErrors.dv = 'Un solo dígito.';
        }
        if (phoneForApi !== '' && !isValidColombianMobile(phoneForApi)) {
            localErrors.phone = 'Debe ser un móvil colombiano de 10 dígitos que empiece por 3.';
        }
        if (isCompany && trimmedLegalName === '') {
            localErrors.legal_name = 'La razón social es obligatoria para empresas.';
        }
        if (trimmedEmail !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
            localErrors.email = 'Email inválido.';
        }
        if (Object.keys(localErrors).length > 0) {
            setErrors(localErrors);
            return;
        }

        setSubmitting(true);
        setErrors({});
        setTopError(null);

        try {
            const response = await apiFetch('/api/v1/clients', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    kind: form.kind,
                    doc_type: form.doc_type,
                    doc_number: trimmedDoc,
                    dv: form.dv === '' ? null : form.dv,
                    phone: phoneForApi === '' ? null : phoneForApi,
                    name: trimmedName,
                    legal_name: trimmedLegalName === '' ? null : trimmedLegalName,
                    email: trimmedEmail === '' ? null : trimmedEmail,
                    notes: trimmedNotes === '' ? null : trimmedNotes,
                }),
            });

            if (response.ok) {
                const body = (await response.json()) as { data: CreatedClient };
                setForm(INITIAL_FORM);
                onCreated(body.data);
                onOpenChange(false);
                return;
            }

            if (response.status === 422) {
                try {
                    const body = await response.clone().json();
                    const fieldErrors = (body?.errors ?? null) as Record<string, string[]> | null;
                    if (fieldErrors) {
                        const mapped: Record<string, string> = {};
                        for (const [field, messages] of Object.entries(fieldErrors)) {
                            if (messages.length > 0) {
                                mapped[field] = messages[0];
                            }
                        }
                        setErrors(mapped);
                        return;
                    }
                    if (typeof body?.message === 'string') {
                        setTopError(body.message);
                        return;
                    }
                } catch {
                    // dejar caer.
                }
            }

            if (response.status === 403) {
                setTopError('No tienes permiso para registrar contactos.');
                return;
            }

            setTopError('No fue posible registrar el contacto. Intenta de nuevo.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    resetAndClose();
                } else {
                    onOpenChange(true);
                }
            }}
        >
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Nuevo contacto</DialogTitle>
                    <DialogDescription>
                        El documento es la clave única por empresa. Si ya existe un contacto con ese documento, el sistema te avisa. El teléfono es
                        opcional y puede compartirse entre familiares.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Selector de naturaleza — define qué doc_types se permiten */}
                    <div className="space-y-1.5">
                        <Label>Tipo de contacto</Label>
                        <div className="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                onClick={() => setKind('natural')}
                                disabled={submitting}
                                className={`flex items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors disabled:opacity-50 ${
                                    !isCompany
                                        ? 'border-primary bg-primary/5 text-primary'
                                        : 'border-input bg-background text-muted-foreground hover:border-primary/40'
                                }`}
                            >
                                <User className="h-4 w-4" />
                                Persona natural
                            </button>
                            <button
                                type="button"
                                onClick={() => setKind('company')}
                                disabled={submitting}
                                className={`flex items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors disabled:opacity-50 ${
                                    isCompany
                                        ? 'border-primary bg-primary/5 text-primary'
                                        : 'border-input bg-background text-muted-foreground hover:border-primary/40'
                                }`}
                            >
                                <Building2 className="h-4 w-4" />
                                Empresa
                            </button>
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="new-client-name">{isCompany ? 'Nombre comercial' : 'Nombre completo'}</Label>
                        <Input
                            id="new-client-name"
                            type="text"
                            value={form.name}
                            onChange={(e) => setField('name', sanitizePlainText(e.target.value, 120, false, false))}
                            placeholder={isCompany ? 'Ej: Soluciones Andinas' : 'Ej: María Pérez'}
                            disabled={submitting}
                            autoFocus
                            maxLength={120}
                        />
                        {errors.name && (
                            <p className="text-xs text-[color:var(--color-status-critical)]" role="alert">
                                {errors.name}
                            </p>
                        )}
                    </div>

                    <div className="grid grid-cols-12 gap-3">
                        <div className="col-span-5 space-y-1.5">
                            <Label htmlFor="new-client-doc-type">Documento</Label>
                            <Select value={form.doc_type} onValueChange={(v) => setField('doc_type', v)} disabled={submitting}>
                                <SelectTrigger id="new-client-doc-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {docTypeOptions.map((t) => (
                                        <SelectItem key={t.value} value={t.value}>
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="col-span-5 space-y-1.5">
                            <Label htmlFor="new-client-doc-number">Número</Label>
                            <Input
                                id="new-client-doc-number"
                                type="text"
                                value={form.doc_number}
                                onChange={(e) => setField('doc_number', e.target.value)}
                                placeholder={isCompany ? '900456789' : '1098765432'}
                                disabled={submitting}
                                maxLength={30}
                            />
                            {errors.doc_number && (
                                <p className="text-xs text-[color:var(--color-status-critical)]" role="alert">
                                    {errors.doc_number}
                                </p>
                            )}
                        </div>
                        <div className="col-span-2 space-y-1.5">
                            <Label htmlFor="new-client-dv">DV</Label>
                            <Input
                                id="new-client-dv"
                                type="text"
                                value={form.dv}
                                onChange={(e) => setField('dv', e.target.value)}
                                placeholder="0"
                                disabled={submitting || !isCompany}
                                maxLength={1}
                                inputMode="numeric"
                            />
                            {errors.dv && (
                                <p className="text-xs text-[color:var(--color-status-critical)]" role="alert">
                                    {errors.dv}
                                </p>
                            )}
                        </div>
                    </div>

                    {isCompany && (
                        <div className="space-y-1.5">
                            <Label htmlFor="new-client-legal-name">Razón social</Label>
                            <Input
                                id="new-client-legal-name"
                                type="text"
                                value={form.legal_name}
                                onChange={(e) => setField('legal_name', sanitizePlainText(e.target.value, 160, false, false))}
                                placeholder="SOLUCIONES ANDINAS SAS"
                                disabled={submitting}
                                maxLength={160}
                            />
                            {errors.legal_name && (
                                <p className="text-xs text-[color:var(--color-status-critical)]" role="alert">
                                    {errors.legal_name}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label htmlFor="new-client-phone">Teléfono (opcional)</Label>
                        <Input
                            id="new-client-phone"
                            type="tel"
                            value={form.phone}
                            onChange={(e) => setField('phone', e.target.value)}
                            placeholder="3001234567"
                            disabled={submitting}
                            inputMode="tel"
                            maxLength={32}
                        />
                        {errors.phone ? (
                            <p className="text-xs text-[color:var(--color-status-critical)]" role="alert">
                                {errors.phone}
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-xs">Móvil colombiano de 10 dígitos. Puede repetirse entre familiares.</p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="new-client-email">Email (opcional)</Label>
                        <Input
                            id="new-client-email"
                            type="email"
                            value={form.email}
                            onChange={(e) => setField('email', e.target.value)}
                            placeholder={isCompany ? 'facturacion@empresa.com' : 'persona@ejemplo.com'}
                            disabled={submitting}
                            maxLength={120}
                        />
                        {errors.email && (
                            <p className="text-xs text-[color:var(--color-status-critical)]" role="alert">
                                {errors.email}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="new-client-notes">Notas (opcional)</Label>
                        <textarea
                            id="new-client-notes"
                            value={form.notes}
                            onChange={(e) => setField('notes', sanitizePlainText(e.target.value, 1000, true, false))}
                            placeholder="Preferencias, alergias, contexto…"
                            disabled={submitting}
                            maxLength={1000}
                            rows={3}
                            className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-sm focus-visible:ring-1 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        {errors.notes && (
                            <p className="text-xs text-[color:var(--color-status-critical)]" role="alert">
                                {errors.notes}
                            </p>
                        )}
                    </div>

                    {topError && (
                        <p className="text-sm text-[color:var(--color-status-critical)]" role="alert">
                            {topError}
                        </p>
                    )}

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button type="button" variant="outline" onClick={resetAndClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? <LoaderCircle className="mr-1 h-4 w-4 animate-spin" /> : <UserPlus className="mr-1 h-4 w-4" />}
                            Registrar contacto
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
