import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/toast';
import { FileDown, Loader2 } from 'lucide-react';
import { useState } from 'react';

interface ExportPdfButtonProps {
    endpoint: string;
    filters: Record<string, unknown>;
    filename: string;
    disabled?: boolean;
    label?: string;
}

export default function ExportPdfButton({ endpoint, filters, disabled = false, label = 'Exportar PDF' }: ExportPdfButtonProps) {
    const [exporting, setExporting] = useState(false);
    const { showToast } = useToast();

    async function handleExport() {
        const win = window.open('', '_blank');
        setExporting(true);
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/pdf, application/json',
                },
                credentials: 'include',
                body: JSON.stringify({ filters }),
            });

            const contentType = response.headers.get('Content-Type') ?? '';

            if (response.ok && contentType.includes('application/pdf')) {
                const blob = await response.blob();
                const blobUrl = URL.createObjectURL(blob);
                if (win) {
                    win.location.href = blobUrl;
                    setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000);
                } else {
                    window.open(blobUrl, '_blank');
                    setTimeout(() => URL.revokeObjectURL(blobUrl), 1_000);
                }
            } else {
                win?.close();
                const json = await response.json();
                showToast('error', json.message ?? 'Error al generar el PDF. Intenta de nuevo.');
            }
        } catch {
            win?.close();
            showToast('error', 'Error al generar el PDF. Intenta de nuevo.');
        } finally {
            setExporting(false);
        }
    }

    return (
        <Button variant="outline" onClick={handleExport} disabled={exporting || disabled} className="gap-2">
            {exporting ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileDown className="h-4 w-4" />}
            {exporting ? 'Generando PDF...' : label}
        </Button>
    );
}
