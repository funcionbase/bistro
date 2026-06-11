import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

interface BottomSheetProps {
    isOpen: boolean;
    onClose: () => void;
    title: string;
    children: React.ReactNode;
    className?: string;
}

export function BottomSheet({ isOpen, onClose, title, children, className }: BottomSheetProps) {
    return (
        <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                side="bottom"
                className={cn('max-h-[90dvh] overflow-y-auto rounded-t-2xl px-0 pb-safe', className)}
            >
                <div aria-hidden className="bg-muted-foreground/30 mx-auto mt-2 h-1 w-12 rounded-full" />
                <SheetHeader className="px-6 pb-2 pt-4">
                    <SheetTitle>{title}</SheetTitle>
                </SheetHeader>
                <div className="px-6 pb-6">{children}</div>
            </SheetContent>
        </Sheet>
    );
}
