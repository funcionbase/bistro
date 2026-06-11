import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';

interface BottomSheetDialogProps {
    isOpen: boolean;
    onClose: () => void;
    title: string;
    children: React.ReactNode;
    className?: string;
}

export function BottomSheetDialog({ isOpen, onClose, title, children, className }: BottomSheetDialogProps) {
    const isMobile = useIsMobile();

    if (isMobile) {
        return (
            <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
                <SheetContent
                    side="bottom"
                    className={cn('max-h-[90dvh] overflow-y-auto rounded-t-2xl px-0 pb-safe', className)}
                >
                    <div aria-hidden className="bg-muted-foreground/30 mx-auto mb-4 h-1 w-12 rounded-full" />
                    <SheetHeader className="px-6 pb-2">
                        <SheetTitle>{title}</SheetTitle>
                    </SheetHeader>
                    <div className="px-6 pb-6">{children}</div>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className={cn('max-w-md', className)}>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                </DialogHeader>
                {children}
            </DialogContent>
        </Dialog>
    );
}
