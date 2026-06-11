import { ShortcutsList } from '@/components/shortcut-tooltip';
import { BottomSheetDialog } from '@/components/ui/bottom-sheet-dialog';
import { APP_SHORTCUTS } from '@/lib/shortcuts';

interface ShortcutsHelpModalProps {
    isOpen: boolean;
    onClose: () => void;
}

/**
 * Modal de ayuda con la lista completa de atajos disponibles. Se invoca con
 * la tecla `?` desde cualquier pantalla autenticada.
 */
export function ShortcutsHelpModal({ isOpen, onClose }: ShortcutsHelpModalProps) {
    const grouped = APP_SHORTCUTS.reduce<Record<string, (typeof APP_SHORTCUTS)[number][]>>((acc, s) => {
        const cat = s.category ?? 'Otros';
        if (!acc[cat]) acc[cat] = [];
        acc[cat].push(s);
        return acc;
    }, {});

    return (
        <BottomSheetDialog isOpen={isOpen} onClose={onClose} title="Atajos de teclado">
            <div className="space-y-4" data-testid="shortcuts-help-content">
                {Object.entries(grouped).map(([cat, items]) => (
                    <section key={cat}>
                        <h3 className="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">{cat}</h3>
                        <ShortcutsList shortcuts={items.map((s) => ({ keys: s.keys as string[], description: s.description, chord: s.chord }))} />
                    </section>
                ))}
                <div className="space-y-1 border-t border-gray-100 pt-3">
                    <p className="text-xs text-gray-400">
                        Las teclas de <span className="font-medium">Navegación</span> se pulsan en secuencia: primero{' '}
                        <kbd className="rounded border bg-gray-100 px-1 font-mono">G</kbd>, luego la del destino (ej.{' '}
                        <kbd className="rounded border bg-gray-100 px-1 font-mono">G</kbd> <kbd className="rounded border bg-gray-100 px-1 font-mono">D</kbd>{' '}
                        → Dashboard).
                    </p>
                    <p className="text-xs text-gray-400">
                        Pulsa <kbd className="rounded border bg-gray-100 px-1 font-mono">?</kbd> en cualquier momento para abrir esta ayuda.
                    </p>
                </div>
            </div>
        </BottomSheetDialog>
    );
}
