import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useToast } from '@/components/ui/toast';
import { apiFetch } from '@/lib/api';
import { LoaderCircle, ShieldAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type WhatsappAction = 'connect' | 'swap' | 'disconnect' | 'update';

interface RequestResponse {
    data: {
        expires_at: string;
        owner_email_masked: string;
        attempts_allowed: number;
    };
}

interface Props {
    open: boolean;
    action: WhatsappAction;
    title: string;
    description?: string;
    confirmLabel?: string;
    onClose: () => void;
    onVerified: (code: string) => Promise<void> | void;
}

const RESEND_COOLDOWN_SECONDS = 60;

export default function WhatsappVerificationCodeModal({ open, action, title, description, confirmLabel = 'Confirmar', onClose, onVerified }: Props) {
    const { showToast } = useToast();
    const [code, setCode] = useState('');
    const [requesting, setRequesting] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [requested, setRequested] = useState(false);
    const [maskedEmail, setMaskedEmail] = useState('');
    const [expiresAt, setExpiresAt] = useState<Date | null>(null);
    const [now, setNow] = useState(Date.now());
    const [cooldown, setCooldown] = useState(0);
    const inputRef = useRef<HTMLInputElement | null>(null);

    useEffect(() => {
        if (!open) {
            setCode('');
            setRequested(false);
            setMaskedEmail('');
            setExpiresAt(null);
            setCooldown(0);
            return;
        }

        void requestCode();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const id = window.setInterval(() => {
            setNow(Date.now());
            setCooldown((c) => Math.max(0, c - 1));
        }, 1000);
        return () => window.clearInterval(id);
    }, [open]);

    async function requestCode() {
        setRequesting(true);
        try {
            const res = await apiFetch('/api/v1/whatsapp/verification/request', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action }),
            });
            const data = (await res.json()) as RequestResponse | { message?: string };

            if (!res.ok) {
                const msg = (data as { message?: string }).message ?? 'No fue posible enviar el codigo.';
                showToast('error', msg);
                return;
            }

            const ok = data as RequestResponse;
            setMaskedEmail(ok.data.owner_email_masked);
            setExpiresAt(new Date(ok.data.expires_at));
            setRequested(true);
            setCooldown(RESEND_COOLDOWN_SECONDS);
            inputRef.current?.focus();
            showToast('success', 'Codigo enviado al correo del propietario.');
        } catch {
            showToast('error', 'Error de conexion al solicitar el codigo.');
        } finally {
            setRequesting(false);
        }
    }

    async function handleSubmit() {
        if (code.length !== 6) {
            showToast('error', 'El codigo debe tener 6 digitos.');
            return;
        }
        setSubmitting(true);
        try {
            await onVerified(code);
        } finally {
            setSubmitting(false);
        }
    }

    const remainingSeconds = expiresAt ? Math.max(0, Math.floor((expiresAt.getTime() - now) / 1000)) : 0;
    const minutes = Math.floor(remainingSeconds / 60);
    const seconds = remainingSeconds % 60;
    const expired = requested && remainingSeconds === 0;

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ShieldAlert className="h-5 w-5 text-[color:var(--color-status-warning)]" />
                        {title}
                    </DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {!requested && requesting && <p className="text-muted-foreground text-sm">Enviando codigo al propietario&hellip;</p>}

                    {requested && (
                        <>
                            <p className="text-muted-foreground text-sm">
                                Hemos enviado un codigo de 6 digitos a <strong>{maskedEmail}</strong> (correo del propietario de la empresa). Pidele
                                que te lo comparta y pegalo abajo.
                            </p>

                            <div className="space-y-2">
                                <Label htmlFor="otp-code">Codigo de 6 digitos</Label>
                                <Input
                                    id="otp-code"
                                    ref={inputRef}
                                    inputMode="numeric"
                                    maxLength={6}
                                    pattern="[0-9]{6}"
                                    placeholder="------"
                                    value={code}
                                    onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                                    className="text-center font-mono text-2xl tracking-[0.6em]"
                                    autoFocus
                                />
                            </div>

                            <div className="text-muted-foreground flex items-center justify-between text-xs">
                                {expired ? (
                                    <span className="text-[color:var(--color-status-critical)]">El codigo expiro. Solicita uno nuevo.</span>
                                ) : (
                                    <span>
                                        Vence en {String(minutes).padStart(2, '0')}:{String(seconds).padStart(2, '0')}
                                    </span>
                                )}
                                <button
                                    type="button"
                                    disabled={cooldown > 0 || requesting}
                                    onClick={requestCode}
                                    className="text-primary font-medium disabled:opacity-50"
                                >
                                    {cooldown > 0 ? `Reenviar en ${cooldown}s` : 'Reenviar codigo'}
                                </button>
                            </div>
                        </>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={submitting}>
                        Cancelar
                    </Button>
                    <Button onClick={handleSubmit} disabled={!requested || code.length !== 6 || submitting || expired}>
                        {submitting && <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
