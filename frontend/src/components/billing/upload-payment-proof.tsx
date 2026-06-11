import { Button } from '@/components/ui/button';
import { apiFetch } from '@/lib/api';
import { Loader2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

interface Props {
    onUploaded?: () => void;
}

const ACCEPTED = '.pdf,.jpg,.jpeg,.png';
const MAX_BYTES = 10 * 1024 * 1024;

/**
 * Form de subida de comprobante de pago (#175). Sube a
 * POST /api/v1/billing/payment-proofs (multipart). Valida tamaño/tipo en
 * cliente; el backend re-valida con PaymentProofUploadRequest (autoritativo).
 */
export default function UploadPaymentProof({ onUploaded }: Props) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        setError(null);
        setSuccess(null);
        const f = e.target.files?.[0] ?? null;
        if (f && f.size > MAX_BYTES) {
            setError('El comprobante no puede pesar más de 10 MB.');
            return;
        }
        setFile(f);
    };

    const handleSubmit = async () => {
        if (!file) return;
        setSubmitting(true);
        setError(null);
        try {
            const formData = new FormData();
            formData.append('file', file);

            const res = await apiFetch('/api/v1/billing/payment-proofs', {
                method: 'POST',
                body: formData,
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError(data.message ?? 'No pudimos guardar el comprobante. Intenta de nuevo.');
                return;
            }
            setSuccess('Comprobante recibido. Te avisaremos al validarlo.');
            setFile(null);
            if (inputRef.current) inputRef.current.value = '';
            onUploaded?.();
        } catch {
            setError('Error de conexión. Intenta más tarde.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="space-y-3">
            <input
                ref={inputRef}
                type="file"
                accept={ACCEPTED}
                onChange={handleFileChange}
                className="file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 text-muted-foreground block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:px-4 file:py-2 file:text-sm file:font-semibold"
                disabled={submitting}
            />
            <p className="text-muted-foreground text-xs">PDF, JPG o PNG. Máx 10 MB.</p>

            {error && (
                <div className="rounded-md border border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 px-3 py-2 text-sm text-[color:var(--color-status-critical)]">
                    {error}
                </div>
            )}
            {success && (
                <div className="rounded-md border border-[color:var(--color-status-success)]/30 bg-[color:var(--color-status-success)]/10 px-3 py-2 text-sm text-[color:var(--color-status-success)]">
                    {success}
                </div>
            )}

            <Button onClick={handleSubmit} disabled={!file || submitting}>
                {submitting ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Upload className="mr-2 h-4 w-4" />}
                Enviar comprobante
            </Button>
        </div>
    );
}
