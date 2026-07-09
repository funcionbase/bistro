interface StatBlockProps {
    label: string;
    children: React.ReactNode;
}

/** Par label / valor destacado usado por las tarjetas de facturación (suscripción, uso DIAN). */
export function StatBlock({ label, children }: StatBlockProps) {
    return (
        <div className="space-y-1">
            <p className="text-muted-foreground text-[11px] font-semibold tracking-[0.18em] uppercase">{label}</p>
            {children}
        </div>
    );
}
