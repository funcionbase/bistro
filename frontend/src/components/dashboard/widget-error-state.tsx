import { Button } from '@/components/ui/button';
import { AlertTriangle } from 'lucide-react';

interface WidgetErrorStateProps {
    onRetry: () => void;
    message?: string;
}

export default function WidgetErrorState({ onRetry, message = 'No se pudo cargar este panel' }: WidgetErrorStateProps) {
    return (
        <div className="border-border bg-muted flex flex-col items-center justify-center gap-3 rounded-lg border px-6 py-8">
            <AlertTriangle className="h-8 w-8 text-[color:var(--color-status-warning)]" />
            <p className="text-foreground text-sm">{message}</p>
            <Button onClick={onRetry} size="sm">
                Reintentar
            </Button>
        </div>
    );
}
