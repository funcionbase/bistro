import { Info } from 'lucide-react';

export function MenuPriorityBanner() {
    return (
        <div className="bg-muted/40 border-border rounded-xl border px-5 py-4">
            <div className="flex items-start gap-3">
                <Info className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0" />
                <div className="text-foreground text-sm">
                    <strong className="font-semibold">Regla de precedencia de operabilidad:</strong> <span className="font-medium">Excepciones</span>{' '}
                    {'>'} <span className="font-medium">Horario semanal</span> {'>'} <span className="font-medium">Programación de menú</span>.{' '}
                    <span className="text-muted-foreground">
                        Si la empresa está cerrada por cualquiera de estos, el menú público no se muestra aunque esté programado para ese día.
                    </span>
                </div>
            </div>
        </div>
    );
}
