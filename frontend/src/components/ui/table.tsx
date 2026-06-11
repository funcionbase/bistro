import { cn } from '@/lib/utils';
import { forwardRef } from 'react';

/**
 * Tabla canonica de la app — patron v3.1 estandarizado a partir de /coupons.
 *
 * Wrapper: `bg-card overflow-hidden rounded-lg border shadow-sm`
 * Thead:   `bg-muted/50 text-foreground text-xs uppercase`
 * Row:     `hover:bg-muted/40 border-t transition-colors` (sin zebra-striping)
 * Cell:    `px-4 py-3` (denso operativo, touch-target preservado)
 *
 * Para tablas anidadas dentro de Card o paneles con su propio wrapper, pasar
 * `bare` para deshabilitar el shell visual y solo emitir el `<table>` y scroll
 * horizontal.
 */
export const Table = forwardRef<HTMLTableElement, React.HTMLAttributes<HTMLTableElement> & { bare?: boolean }>(
    ({ className, bare = false, ...props }, ref) => {
        const tableEl = (
            <div className="overflow-x-auto">
                <table ref={ref} className={cn('w-full caption-bottom text-sm', className)} {...props} />
            </div>
        );

        if (bare) {
            return tableEl;
        }

        return <div className="bg-card overflow-hidden rounded-lg border shadow-sm">{tableEl}</div>;
    },
);
Table.displayName = 'Table';

export const TableHeader = forwardRef<HTMLTableSectionElement, React.HTMLAttributes<HTMLTableSectionElement>>(
    ({ className, ...props }, ref) => (
        <thead ref={ref} className={cn('bg-muted/50 text-foreground text-xs uppercase', className)} {...props} />
    ),
);
TableHeader.displayName = 'TableHeader';

export const TableBody = forwardRef<HTMLTableSectionElement, React.HTMLAttributes<HTMLTableSectionElement>>(
    ({ className, ...props }, ref) => <tbody ref={ref} className={cn(className)} {...props} />,
);
TableBody.displayName = 'TableBody';

export const TableRow = forwardRef<HTMLTableRowElement, React.HTMLAttributes<HTMLTableRowElement>>(
    ({ className, ...props }, ref) => (
        <tr
            ref={ref}
            className={cn('hover:bg-muted/40 data-[state=selected]:bg-muted border-t transition-colors', className)}
            {...props}
        />
    ),
);
TableRow.displayName = 'TableRow';

export const TableHead = forwardRef<HTMLTableCellElement, React.ThHTMLAttributes<HTMLTableCellElement>>(
    ({ className, ...props }, ref) => (
        <th
            ref={ref}
            className={cn(
                'px-4 py-3 text-left font-semibold [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]',
                className,
            )}
            {...props}
        />
    ),
);
TableHead.displayName = 'TableHead';

export const TableCell = forwardRef<HTMLTableCellElement, React.TdHTMLAttributes<HTMLTableCellElement>>(
    ({ className, ...props }, ref) => (
        <td
            ref={ref}
            className={cn('px-4 py-3 align-middle [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]', className)}
            {...props}
        />
    ),
);
TableCell.displayName = 'TableCell';
