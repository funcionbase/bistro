import { Button } from '@/components/ui/button';
import { Share, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const DISMISS_KEY = 'pwa_ios_hint_dismissed_at';
const DISMISS_TTL_MS = 1000 * 60 * 60 * 24 * 14;

function isIosSafari(): boolean {
    if (typeof navigator === 'undefined') return false;
    const ua = navigator.userAgent;
    const isIos = /iPad|iPhone|iPod/.test(ua);
    const isWebkit = /WebKit/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);
    return isIos && isWebkit;
}

function isStandalone(): boolean {
    if (typeof window === 'undefined') return false;
    return (
        window.matchMedia?.('(display-mode: standalone)').matches || (window.navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

function isDismissed(): boolean {
    try {
        const raw = window.localStorage.getItem(DISMISS_KEY);
        if (!raw) return false;
        const ts = Number(raw);
        if (!Number.isFinite(ts)) return false;
        return Date.now() - ts < DISMISS_TTL_MS;
    } catch {
        return false;
    }
}

export default function IosInstallHint() {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!isIosSafari() || isStandalone() || isDismissed()) return;
        setVisible(true);
    }, []);

    if (!visible) return null;

    const dismiss = () => {
        try {
            window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
        } catch {
            // ignore
        }
        setVisible(false);
    };

    return (
        <div className="fixed inset-x-0 bottom-0 z-50 px-4 pb-4 sm:right-4 sm:bottom-4 sm:left-auto sm:max-w-sm sm:px-0">
            <div className="bg-card rounded-xl border border-[color:var(--color-status-warning)]/30 p-4 shadow-lg">
                <div className="flex items-start gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[color:var(--color-status-warning)]/15 text-[color:var(--color-status-warning)]">
                        <Share className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold">Instala bistro en este iPhone/iPad</p>
                        <ol className="text-muted-foreground mt-1 list-decimal pl-4 text-xs">
                            <li>Toca el botón Compartir en Safari</li>
                            <li>Selecciona &laquo;Añadir a pantalla de inicio&raquo;</li>
                        </ol>
                        <div className="mt-3">
                            <Button size="sm" variant="outline" onClick={dismiss}>
                                Entendido
                            </Button>
                        </div>
                    </div>
                    <button type="button" onClick={dismiss} aria-label="Cerrar" className="text-muted-foreground hover:text-foreground -m-1 p-1">
                        <X className="h-4 w-4" />
                    </button>
                </div>
            </div>
        </div>
    );
}
