import { Button } from '@/components/ui/button';
import { Download, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const DISMISS_KEY = 'pwa_install_dismissed_at';
const DISMISS_TTL_MS = 1000 * 60 * 60 * 24 * 14;

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
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

export default function InstallPwaPrompt() {
    const [deferred, setDeferred] = useState<BeforeInstallPromptEvent | null>(null);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (isStandalone() || isDismissed()) return;

        const handler = (event: Event) => {
            event.preventDefault();
            setDeferred(event as BeforeInstallPromptEvent);
            setVisible(true);
        };

        window.addEventListener('beforeinstallprompt', handler);
        window.addEventListener('appinstalled', () => setVisible(false));

        return () => {
            window.removeEventListener('beforeinstallprompt', handler);
        };
    }, []);

    if (!visible || !deferred) return null;

    const dismiss = () => {
        try {
            window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
        } catch {
            // localStorage puede estar bloqueado en modo privado; no es crítico.
        }
        setVisible(false);
    };

    const install = async () => {
        await deferred.prompt();
        const { outcome } = await deferred.userChoice;
        if (outcome === 'accepted') {
            setVisible(false);
        } else {
            dismiss();
        }
    };

    return (
        <div
            role="dialog"
            aria-labelledby="pwa-install-title"
            aria-describedby="pwa-install-desc"
            className="fixed inset-x-0 bottom-0 z-50 px-4 pb-4 sm:right-4 sm:bottom-4 sm:left-auto sm:max-w-sm sm:px-0"
        >
            <div className="bg-card border-border text-card-foreground rounded-2xl border p-4 shadow-lg">
                <div className="flex items-start gap-3">
                    <div aria-hidden className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-xl">
                        <Download className="size-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p id="pwa-install-title" className="text-foreground text-sm font-semibold tracking-tight">
                            Instala bistro en este dispositivo
                        </p>
                        <p id="pwa-install-desc" className="text-muted-foreground mt-0.5 text-xs">
                            Más rápido · Funciona sin internet
                        </p>
                        <div className="mt-3 flex items-center gap-2">
                            <Button size="sm" onClick={install}>
                                Instalar
                            </Button>
                            <Button size="sm" variant="ghost" onClick={dismiss}>
                                Ahora no
                            </Button>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={dismiss}
                        aria-label="Cerrar"
                        className="text-muted-foreground hover:text-foreground focus:ring-ring -m-1 rounded-md p-1 focus:ring-2 focus:outline-none"
                    >
                        <X className="size-4" />
                    </button>
                </div>
            </div>
        </div>
    );
}
