import { AppLink } from '@/components/app-link';
import { SalaryReveal } from '@/components/employees/salary-reveal';
import { EditPersonalInfoDialog, type UpdatedUser } from '@/components/me/edit-personal-info-dialog';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { DetailRow } from '@/components/ui/detail-row';
import { EmployeeDetailSkeleton } from '@/components/ui/employee-detail-skeleton';
import { PageHeader } from '@/components/ui/page-header';
import { PositionTag } from '@/components/ui/position-tag';
import { employeeStatusBadge, employeeStatusLabel, useEmployeeStatuses } from '@/hooks/use-employee-statuses';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { AlertCircle, Briefcase, CalendarDays, IdCard, Info, Pencil, UserCircle, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';


type UserProfile = {
    id: string;
    name: string;
    first_name: string | null;
    last_name: string | null;
    email: string;
    cedula: string | null;
    status: string;
};

type MeData = {
    user: UserProfile;
    role: { id: string; name: string } | null;
    active_company_name: string | null;
};

type EmployeeProfile = {
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

const userStatusMeta: Record<string, { label: string; variant: 'safe' | 'warning' | 'critical' | 'secondary' }> = {
    active: { label: 'Activo', variant: 'safe' },
    invited: { label: 'Invitado', variant: 'warning' },
    inactive: { label: 'Inactivo', variant: 'critical' },
};

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso + 'T00:00:00').toLocaleDateString('es-CO', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

export default function MeIndex() {
    const token = useToken();
    const employeeStatuses = useEmployeeStatuses();
    const [data, setData] = useState<MeData | null>(null);
    const [employee, setEmployee] = useState<EmployeeProfile | null>(null);
    const [loading, setLoading] = useState(true);
    const [employeeMissing, setEmployeeMissing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [editOpen, setEditOpen] = useState(false);

    function handleUpdated(updated: UpdatedUser) {
        setData((prev) =>
            prev
                ? {
                      ...prev,
                      user: {
                          ...prev.user,
                          name: updated.name,
                          first_name: updated.first_name,
                          last_name: updated.last_name,
                      },
                  }
                : prev,
        );
    }

    useEffect(() => {
        if (!token) {
            setLoading(false);
            setError('No hay sesión activa.');
            return;
        }

        (async () => {
            try {
                const [meRes, profileRes] = await Promise.all([apiFetch('/api/v1/me'), apiFetch('/api/v1/me/profile')]);

                if (!meRes.ok) {
                    setError('No se pudo cargar el perfil. Reintenta en unos segundos.');
                    return;
                }
                setData(await meRes.json());

                if (profileRes.ok) {
                    const json = await profileRes.json();
                    setEmployee(json.data);
                } else if (profileRes.status === 404) {
                    setEmployeeMissing(true);
                }
            } catch {
                setError('Error de conexión. Verifica tu red e intenta de nuevo.');
            } finally {
                setLoading(false);
            }
        })();
    }, [token]);

    const fullName = data?.user
        ? data.user.first_name && data.user.last_name
            ? `${data.user.first_name} ${data.user.last_name}`
            : data.user.name
        : '';

    const headerActions = (
        <>
            <AppLink href="/me/agenda">
                <Button variant="outline" size="sm">
                    <CalendarDays className="mr-1.5 h-4 w-4" />
                    Mi agenda
                </Button>
            </AppLink>
        </>
    );

    const description = data?.active_company_name
        ? `Empresa activa: ${data.active_company_name}`
        : 'Información de tu cuenta y vinculación con la empresa activa.';

    if (loading) {
        return (
            <PageShell title="Mi perfil">
                <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                    <PageHeader eyebrow="MI PERFIL" title="Mi perfil" description="Cargando información…" />
                    <EmployeeDetailSkeleton />
                </div>
            </PageShell>
        );
    }

    const userStatus = data?.user.status ? userStatusMeta[data.user.status] : null;
    const vinculation = employee
        ? {
              label: employeeStatusLabel(employeeStatuses, employee.vinculation_status),
              variant: employeeStatusBadge(employeeStatuses, employee.vinculation_status),
          }
        : null;

    return (
        <PageShell title="Mi perfil">
            <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                <PageHeader eyebrow="MI PERFIL" title={fullName || 'Mi perfil'} description={description} actions={headerActions} />

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {data && (
                    <DashboardPanel
                        title="Información personal"
                        icon={UserCircle}
                        rightSlot={
                            <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
                                <Pencil className="mr-1.5 h-4 w-4" />
                                Editar
                            </Button>
                        }
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <DetailRow label="Nombre" value={<span className="text-sm font-medium">{fullName}</span>} />
                            <DetailRow label="Correo electrónico" value={<span className="text-sm font-medium">{data.user.email}</span>} />
                            <DetailRow
                                label="Cédula"
                                value={
                                    data.user.cedula ? (
                                        <span className="text-sm font-medium tabular-nums">{data.user.cedula}</span>
                                    ) : (
                                        <span className="text-muted-foreground text-sm italic">Sin registrar</span>
                                    )
                                }
                            />
                            <DetailRow
                                label="Estado de la cuenta"
                                value={
                                    userStatus ? (
                                        <Badge variant={userStatus.variant}>{userStatus.label}</Badge>
                                    ) : (
                                        <span className="text-sm font-medium">{data.user.status}</span>
                                    )
                                }
                            />
                            {data.role && (
                                <DetailRow label="Rol en la empresa" value={<span className="text-sm font-medium">{data.role.name}</span>} />
                            )}
                            {data.active_company_name && (
                                <DetailRow label="Empresa activa" value={<span className="text-sm font-medium">{data.active_company_name}</span>} />
                            )}
                        </div>
                    </DashboardPanel>
                )}

                {employee && (
                    <DashboardPanel
                        title="Ficha de colaborador"
                        icon={Briefcase}
                        rightSlot={vinculation ? <Badge variant={vinculation.variant}>{vinculation.label}</Badge> : null}
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <DetailRow
                                label="Cargo"
                                value={
                                    employee.position ? (
                                        <PositionTag color={employee.position.color} label={employee.position.label} />
                                    ) : (
                                        <span className="text-muted-foreground text-sm italic">Sin asignar</span>
                                    )
                                }
                            />
                            <DetailRow
                                label="Sede principal"
                                value={
                                    employee.primary_branch ? (
                                        <span className="text-sm font-medium">{employee.primary_branch.name}</span>
                                    ) : (
                                        <span className="text-muted-foreground text-sm italic">Sin asignar</span>
                                    )
                                }
                            />
                            <DetailRow
                                label="Tipo de contrato"
                                value={
                                    <span className="text-sm font-medium">
                                        {employee.contract_type ? (contractLabels[employee.contract_type] ?? employee.contract_type) : '—'}
                                    </span>
                                }
                            />
                            <DetailRow
                                label="Tipo de pago"
                                value={<span className="text-sm font-medium">{payTypeLabels[employee.pay_type] ?? employee.pay_type}</span>}
                            />
                            <DetailRow
                                label="Fecha de ingreso"
                                value={<span className="text-sm font-medium">{formatDate(employee.hire_date)}</span>}
                            />
                            <DetailRow
                                label="Teléfono"
                                value={
                                    employee.phone ? (
                                        <span className="text-sm font-medium tabular-nums">{employee.phone}</span>
                                    ) : (
                                        <span className="text-muted-foreground text-sm italic">Sin registrar</span>
                                    )
                                }
                            />
                        </div>
                    </DashboardPanel>
                )}

                {employee && (
                    <DashboardPanel title="Salario" icon={Wallet}>
                        <SalaryReveal endpoint="/api/v1/me/salary" payType={payTypeLabels[employee.pay_type]?.toLowerCase()} />
                        <p className="text-muted-foreground mt-3 text-xs">
                            Al revelar el salario queda registrado en la auditoría con tu identidad y la fecha — práctica obligatoria para cumplir el
                            control interno y la legislación contable colombiana.
                        </p>
                    </DashboardPanel>
                )}

                {employeeMissing && data && (
                    <Alert>
                        <Info className="h-4 w-4" />
                        <AlertDescription>
                            No tienes ficha de colaborador en
                            {data.active_company_name ? ` ${data.active_company_name}` : ' esta empresa'}. Si esto es un error, contacta al
                            propietario o un administrador para que te registre.
                        </AlertDescription>
                    </Alert>
                )}

                {data && !employee && !employeeMissing && !error && (
                    <Alert variant="warning">
                        <IdCard className="h-4 w-4" />
                        <AlertDescription>
                            No pudimos cargar tu ficha de colaborador. Recarga la página o avisa a soporte si persiste.
                        </AlertDescription>
                    </Alert>
                )}

                {data && (
                    <EditPersonalInfoDialog
                        open={editOpen}
                        onOpenChange={setEditOpen}
                        current={{
                            first_name: data.user.first_name,
                            last_name: data.user.last_name,
                            email: data.user.email,
                            cedula: data.user.cedula,
                        }}
                        onUpdated={handleUpdated}
                    />
                )}
            </div>
        </PageShell>
    );
}
