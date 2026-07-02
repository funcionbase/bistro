import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Supplier, SupplierDocumentType, SupplierFormPayload } from '@/types/suppliers';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

const DOC_TYPES: { value: SupplierDocumentType; label: string }[] = [
    { value: 'NIT', label: 'NIT' },
    { value: 'CC', label: 'CC' },
    { value: 'CE', label: 'CE' },
    { value: 'PAS', label: 'Pasaporte' },
    { value: 'OTRO', label: 'Otro' },
];

interface Props {
    open: boolean;
    onClose: () => void;
    onSubmit: (payload: SupplierFormPayload) => Promise<void>;
    editing: Supplier | null;
    submitting: boolean;
    errors: Record<string, string[]>;
}

export function SupplierFormModal({ open, onClose, onSubmit, editing, submitting, errors }: Props) {
    const [name, setName] = useState('');
    const [docType, setDocType] = useState<SupplierDocumentType | ''>('');
    const [docNumber, setDocNumber] = useState('');
    const [contactName, setContactName] = useState('');
    const [email, setEmail] = useState('');
    const [phone, setPhone] = useState('');
    const [address, setAddress] = useState('');
    const [terms, setTerms] = useState('0');
    const [notes, setNotes] = useState('');

    useEffect(() => {
        if (open) {
            setName(editing?.name ?? '');
            setDocType((editing?.document_type as SupplierDocumentType) ?? '');
            setDocNumber(editing?.document_number ?? '');
            setContactName(editing?.contact_name ?? '');
            setEmail(editing?.email ?? '');
            setPhone(editing?.phone ?? '');
            setAddress(editing?.address ?? '');
            setTerms(String(editing?.payment_terms_days ?? 0));
            setNotes(editing?.notes ?? '');
        }
    }, [open, editing]);

    const err = (f: string) => errors[f]?.[0];

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        await onSubmit({
            name: name.trim(),
            document_type: docType || null,
            document_number: docNumber.trim() || null,
            contact_name: contactName.trim() || null,
            email: email.trim() || null,
            phone: phone.trim() || null,
            address: address.trim() || null,
            payment_terms_days: Number(terms) || 0,
            notes: notes.trim() || null,
        });
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{editing ? 'Editar proveedor' : 'Crear proveedor'}</DialogTitle>
                    <DialogDescription>Datos de contacto y términos comerciales del proveedor.</DialogDescription>
                </DialogHeader>

                <form noValidate onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="name">Nombre / Razón social</Label>
                        <Input id="name" value={name} onChange={(e) => setName(e.target.value)} required maxLength={150} />
                        {err('name') && <p className="text-destructive text-xs">{err('name')}</p>}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="document_type">Tipo doc.</Label>
                            <select
                                id="document_type"
                                value={docType}
                                onChange={(e) => setDocType(e.target.value as SupplierDocumentType | '')}
                                className="border-input bg-background flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-sm"
                            >
                                <option value="">—</option>
                                {DOC_TYPES.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="document_number">Número documento</Label>
                            <Input id="document_number" value={docNumber} onChange={(e) => setDocNumber(e.target.value)} maxLength={32} />
                            {err('document_number') && <p className="text-destructive text-xs">{err('document_number')}</p>}
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="contact_name">Persona de contacto</Label>
                            <Input id="contact_name" value={contactName} onChange={(e) => setContactName(e.target.value)} maxLength={120} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="phone">Teléfono</Label>
                            <Input id="phone" value={phone} onChange={(e) => setPhone(e.target.value)} maxLength={32} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={150} />
                            {err('email') && <p className="text-destructive text-xs">{err('email')}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="payment_terms_days">Plazo de pago (días)</Label>
                            <Input id="payment_terms_days" type="number" min="0" max="365" value={terms} onChange={(e) => setTerms(e.target.value)} />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="address">Dirección</Label>
                        <Input id="address" value={address} onChange={(e) => setAddress(e.target.value)} maxLength={255} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="notes">Notas</Label>
                        <textarea
                            id="notes"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={3}
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm shadow-sm"
                        />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting && <LoaderCircle className="mr-1 h-4 w-4 animate-spin" />}
                            {editing ? 'Guardar' : 'Crear proveedor'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
