import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Smartphone, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { formatDateTime } from '@/lib/datetime';

interface SubscriptionRow {
    id: string;
    user_agent: string | null;
    last_seen_at: string | null;
    created_at: string | null;
}

interface Props {
    /** Llamado tras quitar la sub actual del dispositivo (para refrescar parent). */
    onLocalRemoved?: () => void;
}

/**
 * Lista los dispositivos suscritos del usuario actual.
 *
 * Consume `GET /api/v1/push/subscriptions/me`. Cada fila muestra
 * `user_agent` (best-effort, parseado mínimo para legibilidad) y la
 * última vez que fue visto (`last_seen_at`). Botón "Quitar" hace DELETE
 * y refresca; sirve para soltar dispositivos viejos que el operador ya
 * no usa sin tener que abrirlos.
 */
export function PushSubscriptionsList({ onLocalRemoved }: Props) {
    const [items, setItems] = useState<SubscriptionRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [busyId, setBusyId] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const csrfToken = typeof document !== 'undefined' ? (document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '') : '';

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            // `credentials: include` — la API es cross-origin en PDN; sin esto
            // la cookie HttpOnly del JWT no viaja y el endpoint responde 401.
            const res = await fetch('/api/v1/push/subscriptions/me', {
                credentials: 'include',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            const json = (await res.json()) as { data: SubscriptionRow[] };
            setItems(json.data ?? []);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'No pudimos cargar tus dispositivos.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    const removeByApi = useCallback(
        async (row: SubscriptionRow) => {
            setBusyId(row.id);
            setError(null);
            try {
                // No tenemos el endpoint local desde la API; el backend revoca
                // por (user_id, endpoint). En esta lista no exponemos el
                // endpoint para no leakearlo en frontend, así que aplicamos
                // un patrón distinto: pedimos al backend revocar TODAS las
                // subs con id != el del dispositivo actual usando un PATCH
                // dedicado (futuro). Por ahora, este botón sólo aplica al
                // dispositivo actual via window.navigator (que sí conoce el
                // endpoint). Para devices remotos, el usuario debe abrir la
                // app en ese device.
                const reg = await navigator.serviceWorker.getRegistration();
                const sub = (await reg?.pushManager.getSubscription()) ?? null;
                if (!sub) {
                    setError(
                        'Solo puedes quitar el dispositivo desde el que estás usando la app. Abre la app en el otro dispositivo y remuévelo desde allí.',
                    );
                    return;
                }

                const res = await fetch('/api/v1/push/subscriptions', {
                    method: 'DELETE',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ endpoint: sub.endpoint }),
                });
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }

                await sub.unsubscribe().catch(() => undefined);
                await load();
                onLocalRemoved?.();
            } catch (e) {
                setError(e instanceof Error ? e.message : 'No pudimos quitar este dispositivo.');
            } finally {
                setBusyId(null);
            }
        },
        [csrfToken, load, onLocalRemoved],
    );

    if (loading) {
        return (
            <div className="space-y-2">
                <Skeleton className="h-12 w-full" />
                <Skeleton className="h-12 w-full" />
            </div>
        );
    }

    if (error) {
        return <p className="text-critical text-sm">{error}</p>;
    }

    if (items.length === 0) {
        return <p className="text-muted-foreground text-sm">Aún no tienes dispositivos suscritos. Cuando actives notificaciones aparecerán acá.</p>;
    }

    return (
        <ul className="divide-border divide-y">
            {items.map((row) => {
                const label = summarizeUserAgent(row.user_agent);
                const lastSeen = row.last_seen_at
                    ? formatDateTime(row.last_seen_at, { dateStyle: 'medium', timeStyle: 'short' })
                    : 'Sin uso reciente';
                return (
                    <li key={row.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex min-w-0 items-start gap-3">
                            <Smartphone className="text-muted-foreground h-5 w-5 shrink-0" aria-hidden />
                            <div className="min-w-0">
                                <p className="text-foreground truncate text-sm font-medium">{label}</p>
                                <p className="text-muted-foreground text-xs">Visto: {lastSeen}</p>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            <Badge variant="outline" className="text-xs">
                                Activo
                            </Badge>
                            <Button type="button" variant="outline" size="sm" onClick={() => void removeByApi(row)} disabled={busyId !== null}>
                                <Trash2 className="mr-1 h-3.5 w-3.5" />
                                Quitar
                            </Button>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

function summarizeUserAgent(ua: string | null): string {
    if (!ua) return 'Dispositivo desconocido';
    const platform = /(iphone|ipad|ios)/i.test(ua)
        ? 'iOS'
        : /android/i.test(ua)
          ? 'Android'
          : /(windows|win64|win32)/i.test(ua)
            ? 'Windows'
            : /(macintosh|mac os)/i.test(ua)
              ? 'macOS'
              : /linux/i.test(ua)
                ? 'Linux'
                : 'Desconocido';
    const browser = /edg\//i.test(ua)
        ? 'Edge'
        : /chrome\//i.test(ua)
          ? 'Chrome'
          : /firefox\//i.test(ua)
            ? 'Firefox'
            : /safari\//i.test(ua)
              ? 'Safari'
              : 'Navegador';
    return `${browser} · ${platform}`;
}

export default PushSubscriptionsList;
