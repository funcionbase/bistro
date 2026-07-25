import { Button } from '@/components/ui/button';
import { apiFetch } from '@/lib/api';
import { Eye, EyeOff, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

type SalaryData = {
    pay_rate: number;
    base_salary: number | null;
};

interface SalaryRevealProps {
    /**
     * Endpoint que devuelve `{ data: { pay_rate, base_salary, pay_type? } }`.
     * Típicamente `/api/v1/me/salary` o `/api/v1/employees/{id}/salary`.
     * Cada GET queda auditado en el backend (`employee.salary_viewed*`).
     */
    endpoint: string;
    /** Tipo de pago (hora, diario, mensual, etc.) que decora el label. */
    payType?: string;
    /**
     * Texto del botón de reveal. Default avisa que queda auditado para
     * disuadir clicks impulsivos.
     */
    revealLabel?: string;
    /** Modo lectura sin opción de revelar (cuando el actor no tiene permiso). */
    readOnly?: boolean;
    /**
     * Callback opcional con los datos del salario al revelarlo. Útil cuando la
     * página padre necesita pre-rellenar formularios con el `pay_rate`.
     */
    onLoaded?: (data: SalaryData) => void;
}

/**
 * Bloque de salario con reveal explícito + audit log al destapar.
 *
 * Patrón usado en `/me`, `/me/perfil` (vista propia) y
 * `/employees/{id}` (vista de manager). El componente:
 *  - Muestra `••••••` y un botón "Revelar (queda auditado)".
 *  - Al destapar, llama `endpoint` (que ya hace el audit log en backend)
 *    y renderiza pay_rate + base_salary con `font-mono tabular-nums`.
 *  - Botón ocultar para volver al estado enmascarado sin nueva llamada.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo de componentes shared)
 * y CLAUDE.md REGLAS CONTABLES (audit obligatorio en salary reveal).
 */
export function SalaryReveal({ endpoint, payType, revealLabel = 'Revelar (queda auditado)', readOnly = false, onLoaded }: SalaryRevealProps) {
    const [salary, setSalary] = useState<SalaryData | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const reveal = async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await apiFetch(endpoint);
            if (res.ok) {
                const json = await res.json();
                const next: SalaryData = {
                    pay_rate: json.data.pay_rate,
                    base_salary: json.data.base_salary,
                };
                setSalary(next);
                onLoaded?.(next);
            } else {
                setError('No se pudo cargar el salario. Intenta de nuevo.');
            }
        } catch {
            setError('Error de conexión al cargar el salario. Intenta de nuevo.');
        } finally {
            setLoading(false);
        }
    };

    if (readOnly) {
        return (
            <div className="flex flex-wrap items-center gap-3">
                <span className="text-muted-foreground font-mono text-base">••••••</span>
                <span className="text-muted-foreground text-xs">No tienes permiso para ver el salario.</span>
            </div>
        );
    }

    if (!salary) {
        return (
            <div className="flex flex-wrap items-center gap-3">
                <span className="text-muted-foreground font-mono text-base">••••••</span>
                <Button variant="outline" size="sm" onClick={() => void reveal()} disabled={loading}>
                    {loading ? <LoaderCircle className="mr-1.5 h-4 w-4 animate-spin" /> : <Eye className="mr-1.5 h-4 w-4" />}
                    {revealLabel}
                </Button>
                {error && <span className="text-[color:var(--color-status-critical)] text-xs" role="alert">{error}</span>}
            </div>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-6">
            <div className="space-y-0.5">
                <div className="text-muted-foreground text-xs tracking-wide uppercase">Tarifa{payType ? ` ${payType}` : ''}</div>
                <div className="font-mono text-lg font-semibold tabular-nums">${salary.pay_rate.toLocaleString('es-CO')}</div>
            </div>
            {salary.base_salary !== null && (
                <div className="space-y-0.5">
                    <div className="text-muted-foreground text-xs tracking-wide uppercase">Salario base</div>
                    <div className="font-mono text-lg font-semibold tabular-nums">${salary.base_salary.toLocaleString('es-CO')}</div>
                </div>
            )}
            <Button variant="ghost" size="sm" onClick={() => setSalary(null)}>
                <EyeOff className="mr-1.5 h-4 w-4" />
                Ocultar
            </Button>
        </div>
    );
}
