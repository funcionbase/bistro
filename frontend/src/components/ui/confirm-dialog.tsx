import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { AlertTriangle, LoaderCircle } from 'lucide-react';

interface ConfirmDialogProps {
    open: boolean;
    title: string;
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    loading?: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}

/**
 * Modal de confirmacion destructiva.
 *
 * Responsive: Dialog centrado en >=md, BottomSheet en mobile (<md) para que
 * el usuario alcance el CTA con el pulgar sin estirarse a la mitad superior.
 *
 * Tokens fijos del DS: icono `text-destructive` sobre `bg-destructive/10`,
 * confirm `bg-destructive text-destructive-foreground` — no usar hex hardcoded.
 */
export function ConfirmDialog({
    open,
    title,
    message,
    confirmLabel = 'Confirmar',
    cancelLabel = 'Cancelar',
    loading = false,
    onConfirm,
    onCancel,
}: ConfirmDialogProps) {
    const isMobile = useIsMobile();
    const handleOpenChange = (next: boolean) => {
        if (!next && !loading) {
            onCancel();
        }
    };

    const Icon = (
        <div className="bg-destructive/10 rounded-full p-2">
            <AlertTriangle className="text-destructive h-5 w-5" />
        </div>
    );

    const Buttons = (
        <>
            <Button variant="outline" onClick={onCancel} disabled={loading} autoFocus className="w-full sm:w-auto">
                {cancelLabel}
            </Button>
            <Button variant="destructive" onClick={onConfirm} disabled={loading} className="w-full sm:w-auto">
                {loading ? <LoaderCircle className="h-4 w-4 animate-spin" /> : confirmLabel}
            </Button>
        </>
    );

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={handleOpenChange}>
                <SheetContent side="bottom" className="rounded-t-2xl">
                    <div className="bg-muted mx-auto mb-4 h-1 w-12 rounded-full" />
                    <SheetHeader>
                        <div className="flex items-start gap-3">
                            {Icon}
                            <SheetTitle className="text-base">{title}</SheetTitle>
                        </div>
                        <SheetDescription className="text-sm">{message}</SheetDescription>
                    </SheetHeader>
                    <SheetFooter className="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end sm:gap-2">
                        {Buttons}
                    </SheetFooter>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-sm sm:rounded-2xl">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        {Icon}
                        <DialogTitle className="text-base">{title}</DialogTitle>
                    </div>
                    <DialogDescription className="pl-12 text-sm">{message}</DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2 sm:gap-2">{Buttons}</DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
