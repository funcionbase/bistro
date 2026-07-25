import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Bot, ShieldAlert, User } from 'lucide-react';

/**
 * Pestaña "Actividad" de una conversación (plan §7.6).
 *
 * Responde tres preguntas y ninguna más: quién, qué y cuándo. El backend no
 * expone `data`, ni la IP, ni el user-agent — esta vista la ve el dueño del
 * restaurante, no un equipo de seguridad, y convertirla en vigilancia sobre los
 * empleados es un producto distinto que nadie pidió.
 */
export interface ChatAuditEntry {
    id: string;
    action: string;
    label: string;
    actor: { id: string | null; name: string | null } | null;
    created_at: string | null;
}

interface Props {
    isOpen: boolean;
    onClose: () => void;
    entries: ChatAuditEntry[];
    loading: boolean;
    error: string | null;
}

/**
 * Hora local de Colombia (UTC-5) en formato de 24 h.
 *
 * `timeZone` explícito y NO el del navegador: el operador puede estar en otra
 * zona y una auditoría que muestra horas distintas según quién la abre no sirve
 * para reconstruir nada.
 */
function formatWhen(iso: string | null): string {
    if (!iso) return '—';

    return new Date(iso).toLocaleString('es-CO', {
        timeZone: 'America/Bogota',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function ActorIcon({ entry }: { entry: ChatAuditEntry }) {
    if (entry.action === 'chat.access.denied') {
        return <ShieldAlert className="size-4 text-[color:var(--color-status-critical)]" aria-hidden />;
    }
    // Sin actor = lo hizo el bot o el sistema. Se rotula explícito en vez de
    // dejar un vacío que se lee como dato faltante.
    if (!entry.actor?.name) {
        return <Bot className="text-muted-foreground size-4" aria-hidden />;
    }
    return <User className="text-muted-foreground size-4" aria-hidden />;
}

export function ChatActivityModal({ isOpen, onClose, entries, loading, error }: Props) {
    return (
        <BottomSheetDialog isOpen={isOpen} onClose={onClose} title="Actividad de la conversación">
            <div className="space-y-3">
                <p className="text-muted-foreground text-xs">
                    Últimos 50 movimientos, en hora de Colombia. Quién abrió la conversación, quién respondió y qué se cambió.
                </p>

                {loading && (
                    <div className="space-y-2">
                        {[0, 1, 2, 3].map((i) => (
                            <Skeleton key={i} className="h-12 w-full" />
                        ))}
                    </div>
                )}

                {error && (
                    <p role="alert" className="text-[color:var(--color-status-critical)] text-sm">
                        {error}
                    </p>
                )}

                {!loading && !error && entries.length === 0 && (
                    <p className="text-muted-foreground py-6 text-center text-sm">Todavía no hay actividad registrada.</p>
                )}

                {!loading && !error && entries.length > 0 && (
                    <ul className="divide-border divide-y">
                        {entries.map((entry) => (
                            <li key={entry.id} className="flex items-start gap-3 py-2">
                                <span className="mt-0.5 shrink-0">
                                    <ActorIcon entry={entry} />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">{entry.label}</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {entry.actor?.name ?? 'Automático'} · {formatWhen(entry.created_at)}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </BottomSheetDialog>
    );
}
