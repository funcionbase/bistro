import { AppLink } from '@/components/app-link';
import EmployeeForm, { type EmployeeFormValues } from '@/components/employee-form';
import { SalaryReveal } from '@/components/employees/salary-reveal';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { DetailRow } from '@/components/ui/detail-row';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { EmployeeDetailSkeleton } from '@/components/ui/employee-detail-skeleton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { employeeStatusBadge, employeeStatusLabel, isAbsenceStatus, useEmployeeStatuses } from '@/hooks/use-employee-statuses';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';
import { useSharedData } from '@/lib/shared-data';

import { AlertCircle, Archive, ArrowLeft, Eye, LoaderCircle, Mail, Pencil, RefreshCw, ShieldCheck, UserCog, Wallet } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';

type EmployeeUser = {
    id: string;
    name: string;
    email: string;
    status: 'active' | 'pending_enrollment' | 'inactive';
    role: { id: string; name: string; slug: string; is_system: boolean } | null;
    linked_at: string | null;
};

type Employee = {
    id: string;
    full_name: string;
    user_id: string | null;
    user: EmployeeUser | null;
    vinculation_status: string;
    vinculation_valid_from: string | null;
    vinculation_valid_until: string | null;
    pay_type: string;
    pay_rate: number | null;
    pay_rate_masked: boolean;
    primary_branch?: { id: string; name?: string } | null;
    position?: { id: string; label?: string; color?: string | null } | null;
    doc_type?: string;
    doc_number?: string;
    first_name?: string;
    last_name?: string;
    email?: string;
    phone?: string | null;
    birth_date?: string | null;
    blood_type?: string | null;
    address?: string | null;
    city?: string | null;
    eps?: string | null;
    arl?: string | null;
    pension_fund?: string | null;
    severance_fund?: string | null;
    bank?: string | null;
    account_type?: string | null;
    account_number?: string | null;
    emergency_contact_name?: string | null;
    emergency_contact_phone?: string | null;
    uniform_size?: string | null;
    contract_type?: string | null;
    base_salary?: number | null;
    hire_date?: string | null;
    min_days_off_override?: number | null;
};


function userStatusLabel(status: EmployeeUser['status']): string {
    switch (status) {
        case 'active':
            return 'Activa';
        case 'pending_enrollment':
            return 'Pendiente de enrolamiento';
        case 'inactive':
            return 'Inactiva';
    }
}

function userStatusVariant(status: EmployeeUser['status']): 'safe' | 'warning' | 'secondary' {
    switch (status) {
        case 'active':
            return 'safe';
        case 'pending_enrollment':
            return 'warning';
        case 'inactive':
            return 'secondary';
    }
}

export default function EmployeesShow() {
    useToken();
    const navigate = useNavigate();
    const employeeStatuses = useEmployeeStatuses();
    const id = useMemo(() => window.location.pathname.split('/').pop()!, []);
    const props = useSharedData();
    const permissions = (props as { permissions?: string[] })?.permissions ?? [];
    const canViewSalary = permissions.includes('employees.view_salary');
    const canUpdate = permissions.includes('employees.update');

    const [searchParams, setSearchParams] = useSearchParams();
    const isEditing = searchParams.get('edit') === '1' && canUpdate;
    const toggleEdit = (next: boolean) => {
        const sp = new URLSearchParams(searchParams);
        if (next) {
            sp.set('edit', '1');
        } else {
            sp.delete('edit');
        }
        setSearchParams(sp, { replace: true });
    };

    const [employee, setEmployee] = useState<Employee | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);
    const [salary, setSalary] = useState<{ pay_rate: number; base_salary: number | null } | null>(null);
    const [stateDialogOpen, setStateDialogOpen] = useState(false);
    const [newStatus, setNewStatus] = useState('active');
    const [validFrom, setValidFrom] = useState('');
    const [validUntil, setValidUntil] = useState('');
    const [stateError, setStateError] = useState<string | null>(null);
    const [stateSubmitting, setStateSubmitting] = useState(false);
    const [confirmArchive, setConfirmArchive] = useState(false);
    const [archiving, setArchiving] = useState(false);

    const load = async () => {
        setLoading(true);
        try {
            const res = await apiFetch(`/api/v1/employees/${id}`);
            if (!res.ok) {
                setError('No se pudo cargar el colaborador.');
                return;
            }
            const json = await res.json();
            setEmployee(json.data);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    const submit = async (values: EmployeeFormValues) => {
        setSubmitting(true);
        setError(null);
        setFieldErrors({});
        const res = await apiFetch(`/api/v1/employees/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(values),
        });
        setSubmitting(false);
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            // 422: errores por campo → inline bajo cada input. El Alert superior
            // queda solo para errores no atribuibles a un campo.
            if (res.status === 422 && data.errors) {
                const mapped: Record<string, string> = {};
                for (const [field, messages] of Object.entries(data.errors as Record<string, string[]>)) {
                    mapped[field] = messages[0] ?? '';
                }
                setFieldErrors(mapped);
                return;
            }
            setError(data.message ?? 'No se pudo guardar.');
            return;
        }
        await load();
        toggleEdit(false);
    };

    const archive = async () => {
        setArchiving(true);
        setError(null);
        try {
            const res = await apiFetch(`/api/v1/employees/${id}/archive`, { method: 'POST' });
            if (res.ok) {
                navigate('/employees');
                return;
            }
            const data = await res.json().catch(() => ({}));
            setError(data.message ?? 'No se pudo archivar el colaborador.');
        } catch {
            setError('Error de conexión al archivar el colaborador.');
        } finally {
            setArchiving(false);
            setConfirmArchive(false);
        }
    };

    const submitState = async () => {
        setStateError(null);
        setStateSubmitting(true);
        const body: Record<string, string> = { status: newStatus };
        if (['vacation', 'sick_leave', 'compensatory'].includes(newStatus)) {
            body.valid_from = validFrom;
            body.valid_until = validUntil;
        }
        const res = await apiFetch(`/api/v1/employees/${id}/vinculation-state`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        setStateSubmitting(false);
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            setStateError(data.message ?? 'No se pudo cambiar el estado.');
            return;
        }
        setStateDialogOpen(false);
        await load();
    };

    if (loading) {
        return (
            <PageShell title="Cargando colaborador">
                <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                    <PageHeader eyebrow="COLABORADORES" title="Cargando…" />
                    <EmployeeDetailSkeleton />
                </div>
            </PageShell>
        );
    }

    if (!employee) {
        return (
            <PageShell title="Colaborador no encontrado">
                <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                    <PageHeader
                        eyebrow="COLABORADORES"
                        title="Colaborador no encontrado"
                        description="El registro solicitado no existe o no tienes acceso."
                        actions={
                            <AppLink href="/employees">
                                <Button variant="outline" size="sm">
                                    <ArrowLeft className="mr-1.5 h-4 w-4" />
                                    Volver al listado
                                </Button>
                            </AppLink>
                        }
                    />
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error ?? 'No encontrado.'}</AlertDescription>
                    </Alert>
                </div>
            </PageShell>
        );
    }

    const validRange =
        employee.vinculation_valid_from && employee.vinculation_valid_until
            ? `${employee.vinculation_valid_from} → ${employee.vinculation_valid_until}`
            : null;

    const titleNode = (
        <span className="inline-flex items-center gap-2">
            {employee.full_name}
            {employee.user_id && <ShieldCheck className="text-muted-foreground h-5 w-5" aria-label="Usuario en el sistema" />}
        </span>
    );

    const headerActions = (
        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <AppLink href="/employees" className="flex-1 sm:flex-initial">
                <Button variant="outline" size="sm" className="w-full sm:w-auto">
                    <ArrowLeft className="mr-1.5 h-4 w-4" />
                    Volver
                </Button>
            </AppLink>
            {canUpdate && (
                isEditing ? (
                    <Button variant="outline" size="sm" onClick={() => toggleEdit(false)} className="flex-1 sm:flex-initial">
                        <Eye className="mr-1.5 h-4 w-4" />
                        Cancelar edición
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" onClick={() => toggleEdit(true)} className="flex-1 sm:flex-initial">
                        <Pencil className="mr-1.5 h-4 w-4" />
                        Activar edición
                    </Button>
                )
            )}
            <Button variant="outline" size="sm" onClick={() => setStateDialogOpen(true)} className="flex-1 sm:flex-initial">
                <RefreshCw className="mr-1.5 h-4 w-4" />
                Cambiar estado
            </Button>
            <Button variant="destructive" size="sm" onClick={() => setConfirmArchive(true)} className="w-full sm:w-auto">
                <Archive className="mr-1.5 h-4 w-4" />
                Archivar
            </Button>
        </div>
    );

    const initial: Partial<EmployeeFormValues> = {
        primary_branch_id: employee.primary_branch?.id ?? '',
        position_id: employee.position?.id ?? null,
        doc_type: employee.doc_type,
        doc_number: employee.doc_number,
        first_name: employee.first_name,
        last_name: employee.last_name,
        email: employee.email,
        phone: employee.phone ?? '',
        birth_date: employee.birth_date ?? '',
        blood_type: employee.blood_type ?? '',
        address: employee.address ?? '',
        city: employee.city ?? '',
        eps: employee.eps ?? '',
        arl: employee.arl ?? '',
        pension_fund: employee.pension_fund ?? '',
        severance_fund: employee.severance_fund ?? '',
        bank: employee.bank ?? '',
        account_type: employee.account_type ?? '',
        account_number: employee.account_number ?? '',
        emergency_contact_name: employee.emergency_contact_name ?? '',
        emergency_contact_phone: employee.emergency_contact_phone ?? '',
        uniform_size: employee.uniform_size ?? '',
        contract_type: employee.contract_type ?? '',
        base_salary: employee.base_salary != null ? String(employee.base_salary) : '',
        pay_type: employee.pay_type,
        pay_rate: salary?.pay_rate !== undefined ? String(salary.pay_rate) : employee.pay_rate !== null ? String(employee.pay_rate) : '0',
        hire_date: employee.hire_date ?? '',
        min_days_off_override: employee.min_days_off_override != null ? String(employee.min_days_off_override) : '',
    };

    return (
        <PageShell title={employee.full_name}>
            <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="COLABORADORES"
                    title={titleNode}
                    description={validRange ? `Vigencia del estado: ${validRange}` : undefined}
                    actions={headerActions}
                />

                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground text-xs tracking-wide uppercase">Estado</span>
                    <Badge variant={employeeStatusBadge(employeeStatuses, employee.vinculation_status)}>
                        {employeeStatusLabel(employeeStatuses, employee.vinculation_status)}
                    </Badge>
                </div>

                <DashboardPanel title="Salario" icon={Wallet}>
                    <SalaryReveal
                        endpoint={`/api/v1/employees/${id}/salary`}
                        payType={employee.pay_type}
                        readOnly={!canViewSalary}
                        onLoaded={(data) => setSalary(data)}
                    />
                </DashboardPanel>

                {employee.user && (
                    <DashboardPanel title="Cuenta de usuario" icon={ShieldCheck}>
                        <div className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                            <DetailRow
                                label="Nombre en la cuenta"
                                uppercase
                                value={<span className="text-foreground text-sm font-medium">{employee.user.name}</span>}
                            />
                            <DetailRow
                                label="Email de acceso"
                                uppercase
                                value={
                                    <span className="text-foreground flex items-center gap-1.5 text-sm">
                                        <Mail className="text-muted-foreground h-3.5 w-3.5 shrink-0" />
                                        <span className="truncate">{employee.user.email}</span>
                                    </span>
                                }
                            />
                            <DetailRow
                                label="Estado de la cuenta"
                                uppercase
                                value={<Badge variant={userStatusVariant(employee.user.status)}>{userStatusLabel(employee.user.status)}</Badge>}
                            />
                            <DetailRow
                                label="Rol asignado"
                                uppercase
                                value={
                                    employee.user.role ? (
                                        <span className="text-foreground flex items-center gap-1.5 text-sm">
                                            <UserCog className="text-muted-foreground h-3.5 w-3.5 shrink-0" />
                                            <span>{employee.user.role.name}</span>
                                            {employee.user.role.is_system && (
                                                <Badge variant="secondary" className="ml-1">
                                                    Sistema
                                                </Badge>
                                            )}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground text-sm italic">Sin rol en esta empresa</span>
                                    )
                                }
                            />
                            {employee.user.linked_at && (
                                <DetailRow
                                    className="sm:col-span-2"
                                    label="Vinculado desde"
                                    uppercase
                                    value={
                                        <span className="text-foreground text-sm">
                                            {new Date(employee.user.linked_at).toLocaleDateString('es-CO', {
                                                day: '2-digit',
                                                month: 'long',
                                                year: 'numeric',
                                                timeZone: 'America/Bogota',
                                            })}
                                        </span>
                                    }
                                />
                            )}
                        </div>
                    </DashboardPanel>
                )}

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <EmployeeForm initial={initial} onSubmit={submit} submitting={submitting} submitLabel="Guardar cambios" readOnly={!isEditing} errors={fieldErrors} />
            </div>

            <Dialog open={stateDialogOpen} onOpenChange={(o) => !stateSubmitting && setStateDialogOpen(o)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cambiar estado de vinculación</DialogTitle>
                        <DialogDescription>
                            Cambios a vacaciones / incapacidad / compensatorio cancelan automáticamente los turnos del rango.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="employee-new-status">Nuevo estado</Label>
                            <Select value={newStatus} onValueChange={setNewStatus}>
                                <SelectTrigger id="employee-new-status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {employeeStatuses.statuses.map((k) => (
                                        <SelectItem key={k} value={k}>
                                            {employeeStatuses.labels[k]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {isAbsenceStatus(employeeStatuses, newStatus) && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="employee-valid-from">Desde</Label>
                                    <Input id="employee-valid-from" type="date" value={validFrom} onChange={(e) => setValidFrom(e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="employee-valid-until">Hasta</Label>
                                    <Input id="employee-valid-until" type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
                                </div>
                            </div>
                        )}
                        {stateError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{stateError}</AlertDescription>
                            </Alert>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setStateDialogOpen(false)} disabled={stateSubmitting}>
                            Cancelar
                        </Button>
                        <Button onClick={submitState} disabled={stateSubmitting}>
                            {stateSubmitting ? <LoaderCircle className="h-4 w-4 animate-spin" /> : 'Aplicar'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirmArchive}
                title="Archivar colaborador"
                message="Sus turnos futuros se cancelarán automáticamente. Esta acción se puede revertir reactivando el registro."
                confirmLabel="Archivar"
                loading={archiving}
                onConfirm={archive}
                onCancel={() => setConfirmArchive(false)}
            />
        </PageShell>
    );
}
