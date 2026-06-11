import { SalaryReveal } from '@/components/employees/salary-reveal';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { DetailRow } from '@/components/ui/detail-row';
import { EmployeeDetailSkeleton } from '@/components/ui/employee-detail-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { PositionTag } from '@/components/ui/position-tag';
import { employeeStatusBadge, employeeStatusLabel, useEmployeeStatuses } from '@/hooks/use-employee-statuses';
import { apiFetch } from '@/lib/api';
import { AlertCircle, Briefcase, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';

type Profile = {
    id: string;
    first_name: string;
    last_name: string;
    email: string;
    phone: string | null;
    position: { label: string; color: string | null } | null;
    primary_branch: { id: string; name: string } | null;
    contract_type: string | null;
    pay_type: string;
    vinculation_status: string;
    hire_date: string | null;
};


const contractLabels: Record<string, string> = {
    fijo: 'Término fijo',
    indefinido: 'Indefinido',
    OPS: 'OPS',
    aprendizaje: 'Aprendizaje',
};

const payTypeLabels: Record<string, string> = {
    hora: 'Por hora',
    diario: 'Diario',
    semanal: 'Semanal',
    quincenal: 'Quincenal',
    mensual: 'Mensual',
};

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso + 'T00:00:00').toLocaleDateString('es-CO', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

export default function MyProfile() {
    const employeeStatuses = useEmployeeStatuses();
    const [profile, setProfile] = useState<Profile | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        (async () => {
            const res = await apiFetch('/api/v1/me/profile');
            if (!res.ok) {
                setError('No tienes perfil de colaborador en esta empresa.');
                setLoading(false);
                return;
            }
            const json = await res.json();
            setProfile(json.data);
            setLoading(false);
        })();
    }, []);

    const fullName = profile ? `${profile.first_name} ${profile.last_name}` : '';

    return (
        <PageShell title="Mi perfil">
            <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="MI PERFIL"
                    title={loading ? 'Mi perfil' : fullName || 'Mi perfil'}
                    description={loading ? 'Cargando información…' : 'Información laboral y salarial vinculada a la empresa activa.'}
                />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {loading ? (
                    <EmployeeDetailSkeleton />
                ) : profile ? (
                    <>
                        <DashboardPanel
                            title="Ficha de colaborador"
                            icon={Briefcase}
                            rightSlot={
                                <Badge variant={employeeStatusBadge(employeeStatuses, profile.vinculation_status)}>
                                    {employeeStatusLabel(employeeStatuses, profile.vinculation_status)}
                                </Badge>
                            }
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <DetailRow label="Nombre" value={<span className="text-sm font-medium">{fullName}</span>} />
                                <DetailRow label="Correo electrónico" value={<span className="text-sm font-medium">{profile.email}</span>} />
                                <DetailRow
                                    label="Cargo"
                                    value={
                                        profile.position ? (
                                            <PositionTag color={profile.position.color} label={profile.position.label} />
                                        ) : (
                                            <span className="text-muted-foreground text-sm italic">Sin asignar</span>
                                        )
                                    }
                                />
                                <DetailRow
                                    label="Sede principal"
                                    value={
                                        profile.primary_branch ? (
                                            <span className="text-sm font-medium">{profile.primary_branch.name}</span>
                                        ) : (
                                            <span className="text-muted-foreground text-sm italic">Sin asignar</span>
                                        )
                                    }
                                />
                                <DetailRow
                                    label="Tipo de contrato"
                                    value={
                                        <span className="text-sm font-medium">
                                            {profile.contract_type ? (contractLabels[profile.contract_type] ?? profile.contract_type) : '—'}
                                        </span>
                                    }
                                />
                                <DetailRow
                                    label="Tipo de pago"
                                    value={<span className="text-sm font-medium">{payTypeLabels[profile.pay_type] ?? profile.pay_type}</span>}
                                />
                                <DetailRow
                                    label="Fecha de ingreso"
                                    value={<span className="text-sm font-medium">{formatDate(profile.hire_date)}</span>}
                                />
                                <DetailRow
                                    label="Teléfono"
                                    value={
                                        profile.phone ? (
                                            <span className="text-sm font-medium tabular-nums">{profile.phone}</span>
                                        ) : (
                                            <span className="text-muted-foreground text-sm italic">Sin registrar</span>
                                        )
                                    }
                                />
                            </div>
                        </DashboardPanel>

                        <DashboardPanel title="Salario" icon={Wallet}>
                            <SalaryReveal endpoint="/api/v1/me/salary" payType={payTypeLabels[profile.pay_type]?.toLowerCase()} />
                            <p className="text-muted-foreground mt-3 text-xs">
                                Al revelar el salario queda registrado en la auditoría con tu identidad y la fecha — práctica obligatoria para cumplir
                                el control interno y la legislación contable colombiana.
                            </p>
                        </DashboardPanel>
                    </>
                ) : null}
            </div>
        </PageShell>
    );
}
