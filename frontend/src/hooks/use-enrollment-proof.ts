import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useEffect, useState } from 'react';

/** Respuesta de GET /api/v1/enrollment/proof/preview. */
export interface EnrollmentProofPreview {
    url: string;
    mime_type: string;
    original_filename: string;
    file_size: number;
    uploaded_at: string | null;
}

interface UseEnrollmentProofReturn {
    proofData: EnrollmentProofPreview | null;
    proofLoading: boolean;
    proofError: string | null;
    proofOpening: boolean;
    openProof: () => Promise<void>;
}

/**
 * Carga la metadata de la prueba de pertenencia (evidencia de
 * enrolamiento). El backend gatea el acceso: solo el owner (is_system) o
 * quien subió el documento; si no hay permiso responde 403. `openProof`
 * re-pide una URL firmada fresca (≤ 15 min) y la abre en otra pestaña.
 * Comportamiento idéntico al de la página `company/settings.tsx`.
 */
export function useEnrollmentProof(): UseEnrollmentProofReturn {
    const activeToken = useToken();

    const [proofData, setProofData] = useState<EnrollmentProofPreview | null>(null);
    const [proofLoading, setProofLoading] = useState(true);
    const [proofError, setProofError] = useState<string | null>(null);
    const [proofOpening, setProofOpening] = useState(false);

    // Espera a que el token esté sincronizado antes de hacer fetch
    const [tokenReady, setTokenReady] = useState(false);

    useEffect(() => {
        // Espera un ciclo para que useToken() lo detecte
        setTimeout(() => setTokenReady(true), 0);
    }, [activeToken]);

    useEffect(() => {
        if (!tokenReady) return;
        let isMounted = true;
        apiFetch('/api/v1/enrollment/proof/preview')
            .then(async (res) => {
                const data = await res.json().catch(() => ({}));
                if (!isMounted) return;
                if (res.ok) {
                    setProofData(data as EnrollmentProofPreview);
                } else {
                    setProofError(data.message ?? 'No fue posible cargar la prueba de pertenencia.');
                }
            })
            .catch(() => {
                if (isMounted) setProofError('No fue posible cargar la prueba de pertenencia.');
            })
            .finally(() => {
                if (isMounted) setProofLoading(false);
            });
        return () => {
            isMounted = false;
        };
    }, [tokenReady]);

    // Re-pide una URL firmada fresca (vida ≤ 15 min) y abre el documento en
    // otra pestaña — así el enlace nunca llega expirado.
    async function openProof() {
        setProofOpening(true);
        setProofError(null);
        try {
            const res = await apiFetch('/api/v1/enrollment/proof/preview');
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.url) {
                window.open(data.url, '_blank', 'noopener,noreferrer');
            } else {
                setProofError(data.message ?? 'No fue posible abrir el documento.');
            }
        } catch {
            setProofError('No fue posible abrir el documento.');
        } finally {
            setProofOpening(false);
        }
    }

    return { proofData, proofLoading, proofError, proofOpening, openProof };
}
