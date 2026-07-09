import { StatBlock } from '@/components/billing/stat-block';
import { Card, CardContent } from '@/components/ui/card';
import { DetailRow } from '@/components/ui/detail-row';
import { formatCOP, formatMonthYear } from '@/lib/formatters';
import type { DianUsage } from '@/types';
import { DIAN_DOC_TYPE_LABELS, type DianDocumentType } from '@/types/dian';

interface Props {
    usage: DianUsage;
}

/**
 * Detalle de facturación DIAN del período en curso (Plan Plus): conteo de
 * documentos electrónicos emitidos por resolución + cargo por uso estimado.
 * Vive en el tab "Facturación" de `company/settings.tsx`, debajo de `SubscriptionCard`.
 */
export default function DianUsageCard({ usage }: Props) {
    return (
        <Card className="rounded-2xl shadow-sm">
            <CardContent className="space-y-4 p-6">
                <div className="flex items-baseline justify-between">
                    <p className="text-muted-foreground text-[11px] font-semibold tracking-[0.18em] uppercase">
                        Facturación DIAN — período en curso
                    </p>
                    <p className="text-muted-foreground text-xs">{formatMonthYear(usage.period_from)}</p>
                </div>

                {usage.total_documents === 0 ? (
                    <p className="text-muted-foreground text-sm">Sin documentos electrónicos emitidos este período.</p>
                ) : (
                    <div className="space-y-2">
                        {usage.resolutions.map((r) => (
                            <DetailRow
                                key={r.resolution_id}
                                label={`${DIAN_DOC_TYPE_LABELS[r.document_type as DianDocumentType] ?? r.document_type} · Res. ${r.resolution_number}`}
                                value={`${r.count} documentos`}
                            />
                        ))}
                    </div>
                )}

                <div className="border-border flex flex-wrap gap-8 border-t pt-4">
                    <StatBlock label="Documentos">
                        <p className="text-foreground text-lg font-semibold tabular-nums">{usage.total_documents}</p>
                    </StatBlock>
                    <StatBlock label={`Cargo por uso ($${formatCOP(usage.unit_price)}/doc)`}>
                        <p className="text-foreground text-lg font-semibold tabular-nums">$ {formatCOP(usage.usage_amount)}</p>
                    </StatBlock>
                    <StatBlock label="Total estimado del período">
                        <p className="text-primary text-xl font-bold tabular-nums">$ {formatCOP(usage.estimated_total)}</p>
                    </StatBlock>
                </div>
            </CardContent>
        </Card>
    );
}
