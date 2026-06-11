import { CheckCircle, Info, X, XCircle } from 'lucide-react';
import { createContext, useCallback, useContext, useState } from 'react';

export type ToastType = 'success' | 'error' | 'info';

interface Toast {
    id: string;
    type: ToastType;
    message: string;
}

interface ToastContextValue {
    showToast: (type: ToastType, message: string, duration?: number) => void;
}

const ToastContext = createContext<ToastContextValue>({ showToast: () => {} });

export function ToastProvider({ children }: { children: React.ReactNode }) {
    const [toasts, setToasts] = useState<Toast[]>([]);

    const showToast = useCallback((type: ToastType, message: string, duration = 3500) => {
        const id = crypto.randomUUID();
        setToasts((prev) => [...prev.slice(-2), { id, type, message }]);
        setTimeout(() => setToasts((prev) => prev.filter((t) => t.id !== id)), duration);
    }, []);

    const dismiss = useCallback((id: string) => setToasts((prev) => prev.filter((t) => t.id !== id)), []);

    return (
        <ToastContext.Provider value={{ showToast }}>
            {children}
            <Toaster toasts={toasts} onDismiss={dismiss} />
        </ToastContext.Provider>
    );
}

export function useToast() {
    return useContext(ToastContext);
}

const typeConfig = {
    success: {
        Icon: CheckCircle,
        wrapper: 'bg-[color:var(--color-status-success)]/10 border-[color:var(--color-status-success)]/30',
        text: 'text-[color:var(--color-status-success)]',
        iconClass: 'text-[color:var(--color-status-success)]',
    },
    error: {
        Icon: XCircle,
        wrapper: 'bg-[color:var(--color-status-critical)]/10 border-[color:var(--color-status-critical)]/30',
        text: 'text-[color:var(--color-status-critical)]',
        iconClass: 'text-[color:var(--color-status-critical)]',
    },
    info: {
        Icon: Info,
        wrapper: 'bg-[color:var(--color-status-info)]/10 border-[color:var(--color-status-info)]/30',
        text: 'text-[color:var(--color-status-info)]',
        iconClass: 'text-[color:var(--color-status-info)]',
    },
} as const;

function Toaster({ toasts, onDismiss }: { toasts: Toast[]; onDismiss: (id: string) => void }) {
    if (toasts.length === 0) return null;
    return (
        <div className="fixed bottom-6 right-6 z-[200] flex flex-col gap-2" role="status" aria-live="polite">
            {toasts.map((toast) => {
                const { Icon, wrapper, text, iconClass } = typeConfig[toast.type];
                return (
                    <div
                        key={toast.id}
                        className={`flex min-w-72 items-center gap-3 rounded-xl border px-4 py-3 shadow-lg animate-in fade-in-0 slide-in-from-right-4 duration-300 ${wrapper}`}
                    >
                        <Icon className={`h-4 w-4 shrink-0 ${iconClass}`} />
                        <p className={`flex-1 text-sm font-medium ${text}`}>{toast.message}</p>
                        <button
                            onClick={() => onDismiss(toast.id)}
                            className={`rounded p-0.5 opacity-60 hover:opacity-100 ${text}`}
                            aria-label="Cerrar"
                        >
                            <X className="h-3.5 w-3.5" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
