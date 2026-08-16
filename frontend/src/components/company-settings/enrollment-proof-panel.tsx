import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { Skeleton } from '@/components/ui/skeleton';
import type { EnrollmentProofPreview } from '@/hooks/use-enrollment-proof';
import { FileText, LoaderCircle } from 'lucide-react';
import { formatDateTime } from '@/lib/datetime';

interface EnrollmentProofPanelProps {
    proofData: EnrollmentProofPreview | null;
    proofLoading: boolean;
    proofError: string | null;
    proofOpening: boolean;
    onOpenProof: () => void;
}

/** Formatea un tamaño en bytes a una etiqueta legible (B / KB / MB). */
function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Panel "Prueba de pertenencia": documento de propiedad subido en el
 * enrolamiento. Solo lectura; el backend firma una URL temporal de S3 al
 * abrirlo y gatea el acceso (owner o quien lo subió).
 */
export function EnrollmentProofPanel({ proofData, proofLoading, proofError, proofOpening, onOpenProof }: EnrollmentProofPanelProps) {
    return (
        <DashboardPanel title="Prueba de pertenencia de la empresa" icon={FileText}>
            <p className="text-muted-foreground mb-3 text-xs">
                Documento de propiedad que adjuntaste al registrar la empresa (cámara de comercio, RUT, cédula del representante, etc.).
            </p>
            {proofLoading ? (
                <Skeleton className="h-16 w-full rounded-md" />
            ) : proofData ? (
                <div className="border-border flex flex-wrap items-center gap-4 rounded-md border p-3">
                    <FileText className="text-muted-foreground size-8 shrink-0" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{proofData.original_filename}</p>
                        <p className="text-muted-foreground text-xs">
                            {formatBytes(proofData.file_size)}
                            {proofData.uploaded_at &&
                                ` · subida el ${formatDateTime(proofData.uploaded_at, {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                })}`}
                        </p>
                    </div>
                    <Button type="button" variant="outline" size="sm" onClick={onOpenProof} disabled={proofOpening} className="shrink-0">
                        {proofOpening ? (
                            <LoaderCircle className="mr-1.5 h-4 w-4 animate-spin" />
                        ) : (
                            <FileText className="mr-1.5 h-4 w-4" />
                        )}
                        Ver documento
                    </Button>
                </div>
            ) : (
                <p className="text-muted-foreground text-sm">{proofError ?? 'No hay prueba de pertenencia registrada.'}</p>
            )}
            {proofData && proofError && <p className="mt-2 text-sm text-[color:var(--color-status-critical)]">{proofError}</p>}
        </DashboardPanel>
    );
}
