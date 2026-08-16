import { APP_SHORTCUTS, LEADER_KEY } from '@/lib/shortcuts';

/**
 * Overlay "go to" que aparece al **sostener** la tecla líder `G`.
 *
 * Oscurece la UI y lista los destinos de navegación con su segunda tecla, para
 * que el usuario que no recuerda el atajo pueda elegir sin soltar `G`. Se monta
 * desde `GlobalShortcuts`, que controla `open` según el estado de la tecla.
 *
 * Los chips usan tokens del DS (`bg-muted`, `border-border`, `text-foreground`),
 * nunca gris hardcodeado. El scrim usa `bg-black/70` (misma convención que el
 * overlay de `dialog.tsx`).
 */
export function ShortcutPalette({ open }: { open: boolean }) {
    if (!open) {
        return null;
    }

    const destinations = APP_SHORTCUTS.filter((shortcut) => shortcut.category === 'Navegación' && !shortcut.chord && shortcut.route && shortcut.keys.length === 2);

    return (
        <div
            aria-hidden="true"
            className="animate-in fade-in-0 fixed inset-0 z-[90] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm duration-150"
        >
            <div className="bg-card border-border w-full max-w-2xl rounded-2xl border p-6 shadow-xl">
                <div className="mb-5 flex items-center gap-2 text-sm">
                    <kbd className="border-border bg-muted text-foreground inline-flex h-7 min-w-7 items-center justify-center rounded-md border px-2 font-mono text-sm font-semibold">
                        G
                    </kbd>
                    <span className="text-muted-foreground">Mantené pulsado y elegí un destino</span>
                </div>

                <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                    {destinations.map((shortcut) => (
                        <div key={shortcut.route} className="hover:bg-muted/60 flex items-center gap-3 rounded-lg px-3 py-2 transition-colors">
                            <kbd className="border-border bg-muted text-foreground inline-flex h-7 min-w-7 items-center justify-center rounded-md border px-2 font-mono text-sm font-semibold">
                                {shortcut.keys[1]}
                            </kbd>
                            <span className="text-foreground text-sm">{shortcut.description}</span>
                        </div>
                    ))}
                </div>

                <p className="text-muted-foreground mt-5 text-xs">
                    Soltá <span className="font-medium">{LEADER_KEY}</span> o pulsá <kbd className="border-border bg-muted rounded border px-1 font-mono">Esc</kbd>{' '}
                    para cerrar.
                </p>
            </div>
        </div>
    );
}
