import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import type { WhatsappChannel } from '@/hooks/use-whatsapp-channels';
import { apiFetch } from '@/lib/api';

import { AlertTriangle, CheckCircle2, KeyRound, QrCode, RefreshCw, Smartphone } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

/** El QR de WhatsApp caduca entre ~20 y 60 s. Se pide uno nuevo antes de que muera. */
const DEFAULT_EXPIRES_IN = 40;
/** §8.3: el wizard consulta el estado cada 2 s mientras la pantalla está abierta. */
const POLL_MS = 2000;
/** Tres códigos vencidos sin escanear = el usuario está trabado, no distraído. */
const MAX_EXPIRATIONS = 3;

interface QrLinkerProps {
    channel: WhatsappChannel;
    /** Se dispara una sola vez, cuando el canal pasa a `connected`. */
    onConnected: (channel: WhatsappChannel) => void;
    onError?: (message: string) => void;
}

/**
 * Paso 2 del wizard: vincular el número escaneando el QR (§8.3).
 *
 * Tres cosas que hacen la diferencia y por las que este componente no es solo
 * un `<img>`:
 *
 *  - **Nunca queda un QR muerto en pantalla.** Hay countdown visible y refresco
 *    silencioso al expirar. Un código vencido sin aviso es el motivo más común
 *    de "escaneé y no pasó nada".
 *  - **El estado se anuncia por `aria-live`.** Un QR es inaccesible por
 *    definición; el código de 8 dígitos es la ruta completa alternativa, no un
 *    parche.
 *  - **Tras 3 expiraciones deja de reintentar** y pregunta lo que hay que
 *    preguntar ("¿tenés WhatsApp abierto?"). Refrescar para siempre esconde el
 *    problema en vez de resolverlo.
 */
export function QrLinker({ channel, onConnected, onError }: QrLinkerProps) {
    const [qr, setQr] = useState<string | null>(null);
    const [secondsLeft, setSecondsLeft] = useState(DEFAULT_EXPIRES_IN);
    const [loadingQr, setLoadingQr] = useState(true);
    // Contador de vencimientos en un ref: solo importa para decidir cuándo
    // frenar, no se muestra, así que no dispara re-render.
    const expirationsRef = useRef(0);
    const [state, setState] = useState<'waiting' | 'connecting' | 'connected' | 'stalled'>('waiting');
    const [pairingCode, setPairingCode] = useState<string | null>(null);
    const [pairingOpen, setPairingOpen] = useState(false);
    const [pairingPhone, setPairingPhone] = useState('');
    const [pairingBusy, setPairingBusy] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);

    // `onConnected` se dispara una sola vez: el polling sigue vivo un tick más
    // después de conectar y sin esto dispararía el callback dos veces, abriendo
    // dos veces la pantalla de éxito.
    const notifiedRef = useRef(false);

    const fetchQr = useCallback(async (): Promise<void> => {
        setLoadingQr(true);
        setLocalError(null);
        try {
            const res = await apiFetch(`/api/v1/whatsapp/channels/${channel.id}/qr`);
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                const message = (json as { message?: string }).message ?? 'No pudimos generar el código.';
                setLocalError(message);
                onError?.(message);
                return;
            }
            const data = (json as { data: { qr: string; pairing_code: string | null; expires_in?: number } }).data;
            // Evolution devuelve el PNG en base64; a veces ya con el prefijo de
            // data URI y a veces sin él. Normalizarlo acá evita un `<img>` roto
            // que además no da ninguna pista de por qué está roto.
            setQr(data.qr.startsWith('data:') ? data.qr : `data:image/png;base64,${data.qr}`);
            setSecondsLeft(data.expires_in ?? DEFAULT_EXPIRES_IN);
            if (data.pairing_code) setPairingCode(data.pairing_code);
        } catch {
            const message = 'Error de conexión al pedir el código.';
            setLocalError(message);
            onError?.(message);
        } finally {
            setLoadingQr(false);
        }
    }, [channel.id, onError]);

    useEffect(() => {
        void fetchQr();
    }, [fetchQr]);

    // Countdown + auto-refresh. Al llegar a cero pide otro código, salvo que ya
    // se hayan quemado tres: ahí para y muestra la ayuda concreta.
    useEffect(() => {
        if (state === 'connected' || state === 'stalled') return;

        const tick = setInterval(() => {
            setSecondsLeft((prev) => {
                if (prev > 1) return prev - 1;
                expirationsRef.current += 1;
                if (expirationsRef.current >= MAX_EXPIRATIONS) {
                    setState('stalled');
                } else {
                    void fetchQr();
                }
                return DEFAULT_EXPIRES_IN;
            });
        }, 1000);

        return () => clearInterval(tick);
    }, [state, fetchQr]);

    // Polling del estado real. Se detiene al conectar o al desmontar el modal.
    useEffect(() => {
        if (state === 'connected') return;

        let cancelled = false;

        const poll = async () => {
            try {
                const res = await apiFetch(`/api/v1/whatsapp/channels/${channel.id}/state`);
                if (!res.ok || cancelled) return;
                const json = await res.json();
                const fresh = (json as { data: WhatsappChannel }).data;

                if (fresh.status === 'connected') {
                    setState('connected');
                    if (!notifiedRef.current) {
                        notifiedRef.current = true;
                        onConnected(fresh);
                    }
                } else if (fresh.status === 'verifying') {
                    setState('connecting');
                }
            } catch {
                // Silencioso: un poll perdido se recupera en el siguiente. Cortar
                // el wizard por un timeout de red sería peor que esperar 2 s.
            }
        };

        const interval = setInterval(() => void poll(), POLL_MS);
        return () => {
            cancelled = true;
            clearInterval(interval);
        };
    }, [channel.id, state, onConnected]);

    const requestPairingCode = async () => {
        if (!pairingPhone.trim() || pairingBusy) return;
        setPairingBusy(true);
        setLocalError(null);
        try {
            const res = await apiFetch(`/api/v1/whatsapp/channels/${channel.id}/pairing-code`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ phone_e164: pairingPhone.trim() }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setLocalError((json as { message?: string }).message ?? 'No pudimos generar el código de vinculación.');
                return;
            }
            setPairingCode((json as { data: { pairing_code: string } }).data.pairing_code);
        } catch {
            setLocalError('Error de conexión al pedir el código de vinculación.');
        } finally {
            setPairingBusy(false);
        }
    };

    const restart = () => {
        expirationsRef.current = 0;
        setState('waiting');
        setSecondsLeft(DEFAULT_EXPIRES_IN);
        void fetchQr();
    };

    if (state === 'connected') {
        return (
            <div className="flex flex-col items-center gap-3 py-6 text-center" role="status" aria-live="polite">
                <CheckCircle2 className="h-12 w-12 text-[color:var(--color-status-safe)]" />
                <p className="text-lg font-semibold">¡Listo! Tu WhatsApp quedó conectado.</p>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-col items-center gap-5 sm:flex-row sm:items-start">
                <div className="flex shrink-0 flex-col items-center gap-2">
                    {/* Fondo blanco fijo y sin degradado: el contraste del QR no
                        es decoración, es lo que determina si la cámara lo lee.
                        Por eso NO usa `bg-card`, que en dark mode sería oscuro. */}
                    <div className="rounded-xl bg-white p-3 shadow-sm">
                        {loadingQr || !qr ? (
                            <Skeleton className="h-[240px] w-[240px]" />
                        ) : (
                            <img
                                src={qr}
                                width={240}
                                height={240}
                                className="h-[240px] w-[240px]"
                                alt="Código QR para vincular WhatsApp. Si no podés escanearlo, usá el código de 8 dígitos que está más abajo."
                            />
                        )}
                    </div>
                    <p className="text-muted-foreground text-xs tabular-nums">Se renueva en 0:{String(secondsLeft).padStart(2, '0')}</p>
                </div>

                <ol className="text-foreground w-full space-y-2 text-sm">
                    <li className="flex gap-2">
                        <span className="text-muted-foreground font-mono">1.</span> Abrí WhatsApp en tu celular
                    </li>
                    <li className="flex gap-2">
                        <span className="text-muted-foreground font-mono">2.</span> Tocá ⋮ → Dispositivos vinculados
                    </li>
                    <li className="flex gap-2">
                        <span className="text-muted-foreground font-mono">3.</span> Tocá «Vincular un dispositivo»
                    </li>
                    <li className="flex gap-2">
                        <span className="text-muted-foreground font-mono">4.</span> Apuntá la cámara acá
                    </li>
                </ol>
            </div>

            {/* La máquina de estados se anuncia: para un lector de pantalla es la
                única señal de que algo está pasando. */}
            <p className="text-muted-foreground flex items-center justify-center gap-2 text-sm" role="status" aria-live="polite">
                {state === 'connecting' ? (
                    <>
                        <RefreshCw className="h-4 w-4 animate-spin" />
                        Escaneado. Conectando…
                    </>
                ) : state === 'stalled' ? (
                    <>
                        <AlertTriangle className="h-4 w-4 text-[color:var(--color-status-warning)]" />
                        El código venció {MAX_EXPIRATIONS} veces sin escanearse.
                    </>
                ) : (
                    <>
                        <RefreshCw className="h-4 w-4 animate-spin" />
                        Esperando que escanees…
                    </>
                )}
            </p>

            {state === 'stalled' && (
                <Alert>
                    <Smartphone className="h-4 w-4" />
                    <AlertTitle>¿Tenés WhatsApp abierto en el celular?</AlertTitle>
                    <AlertDescription className="space-y-2">
                        <p>Revisá que el teléfono tenga internet y que estés en Dispositivos vinculados.</p>
                        <Button size="sm" variant="outline" onClick={restart}>
                            <RefreshCw className="mr-2 h-4 w-4" />
                            Empezar de nuevo
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {localError && (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertDescription>{localError}</AlertDescription>
                </Alert>
            )}

            <div className="border-border border-t pt-3">
                {pairingCode ? (
                    <div className="space-y-1 text-center">
                        <p className="text-muted-foreground text-xs">Ingresá este código en tu celular:</p>
                        <p className="font-mono text-2xl font-semibold tracking-[0.3em]">{pairingCode}</p>
                    </div>
                ) : !pairingOpen ? (
                    <Button variant="outline" size="sm" className="w-full" onClick={() => setPairingOpen(true)}>
                        <KeyRound className="mr-2 h-4 w-4" />
                        ¿Sin cámara? Vincular con código de 8 dígitos
                    </Button>
                ) : (
                    <div className="space-y-2">
                        <Label htmlFor="pairing-phone">Número de WhatsApp (con indicativo)</Label>
                        <div className="flex gap-2">
                            <Input
                                id="pairing-phone"
                                value={pairingPhone}
                                onChange={(e) => setPairingPhone(e.target.value)}
                                placeholder="573001234567"
                                inputMode="tel"
                                autoComplete="tel"
                            />
                            <Button onClick={() => void requestPairingCode()} disabled={pairingBusy || !pairingPhone.trim()}>
                                {pairingBusy ? 'Pidiendo…' : 'Obtener'}
                            </Button>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            <QrCode className="mr-1 inline h-3 w-3" />
                            Al pedir el código se genera una vinculación nueva: el QR de arriba deja de servir.
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}
