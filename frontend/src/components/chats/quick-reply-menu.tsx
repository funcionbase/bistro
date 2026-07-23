import { Badge } from '@/components/ui/badge';
import type { QuickReply } from '@/hooks/use-quick-replies';
import { cn } from '@/lib/utils';

interface QuickReplyMenuProps {
    /** Respuestas ya filtradas por lo que el operador escribió tras la `/`. */
    items: QuickReply[];
    /** Índice resaltado, controlado por el teclado del compositor. */
    activeIndex: number;
    onSelect: (reply: QuickReply) => void;
    onHover: (index: number) => void;
    /** true si la empresa aún no tiene ninguna respuesta cargada. */
    empty: boolean;
}

/**
 * Menú de respuestas rápidas que aparece al escribir `/` en el compositor
 * (§8.4b punto 7). Presentacional: la navegación por teclado (↑/↓, Enter, Esc)
 * la maneja el compositor, que es quien tiene el foco del input.
 *
 * Se posiciona SOBRE el compositor (`bottom-full`) porque el input está abajo:
 * abrirlo hacia arriba es lo que deja ver a la vez la lista y lo que se escribe.
 */
export function QuickReplyMenu({ items, activeIndex, onSelect, onHover, empty }: QuickReplyMenuProps) {
    return (
        <div className="bg-popover border-border absolute bottom-full left-0 mb-2 max-h-64 w-full overflow-auto rounded-lg border shadow-md">
            {empty ? (
                <p className="text-muted-foreground p-3 text-xs">No tenés respuestas rápidas. El propietario puede crearlas en Empresa → WhatsApp.</p>
            ) : items.length === 0 ? (
                <p className="text-muted-foreground p-3 text-xs">Ninguna respuesta coincide.</p>
            ) : (
                <ul role="listbox" aria-label="Respuestas rápidas">
                    {items.map((reply, index) => (
                        <li
                            key={reply.id}
                            role="option"
                            aria-selected={index === activeIndex}
                            className={cn('cursor-pointer px-3 py-2', index === activeIndex && 'bg-muted')}
                            onMouseEnter={() => onHover(index)}
                            // `onMouseDown` + preventDefault: sin esto el input pierde
                            // el foco (blur) antes del click y la inserción se pierde.
                            onMouseDown={(event) => {
                                event.preventDefault();
                                onSelect(reply);
                            }}
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="truncate text-sm font-medium">{reply.title}</span>
                                {reply.branch_name ? (
                                    <Badge variant="outline" className="shrink-0 text-[10px]">
                                        {reply.branch_name}
                                    </Badge>
                                ) : (
                                    <span className="text-muted-foreground shrink-0 text-[10px]">Empresa</span>
                                )}
                            </div>
                            <p className="text-muted-foreground truncate text-xs">{reply.body}</p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
