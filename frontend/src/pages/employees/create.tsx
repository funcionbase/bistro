import { AppLink } from '@/components/app-link';
import EmployeeForm, { type EmployeeFormValues } from '@/components/employee-form';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';

import { AlertCircle, ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';


export default function EmployeesCreate() {
    useToken();
    const navigate = useNavigate();
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    const submit = async (values: EmployeeFormValues) => {
        setSubmitting(true);
        setError(null);
        setFieldErrors({});
        try {
            const res = await apiFetch('/api/v1/employees', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(values),
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                // 422: errores por campo → van inline bajo cada input. El Alert
                // superior queda solo para errores no atribuibles a un campo.
                if (res.status === 422 && data.errors) {
                    const mapped: Record<string, string> = {};
                    for (const [field, messages] of Object.entries(data.errors as Record<string, string[]>)) {
                        mapped[field] = messages[0] ?? '';
                    }
                    setFieldErrors(mapped);
                    return;
                }
                setError(data.message ?? 'No se pudo crear el colaborador.');
                return;
            }
            navigate('/employees');
        } catch {
            setError('Error de red al crear colaborador.');
        } finally {
            setSubmitting(false);
        }
    };

    const headerActions = (
        <AppLink href="/employees">
            <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Volver al listado
            </Button>
        </AppLink>
    );

    return (
        <PageShell title="Crear colaborador">
            <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="COLABORADORES"
                    title="Crear colaborador"
                    description="Captura la información HHRR, contractual y bancaria del nuevo miembro del equipo."
                    actions={headerActions}
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <EmployeeForm onSubmit={submit} submitting={submitting} submitLabel="Crear colaborador" errors={fieldErrors} />
            </div>
        </PageShell>
    );
}
