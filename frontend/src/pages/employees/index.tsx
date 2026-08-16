import { AppLink } from '@/components/app-link';
import { PageShell } from '@/components/page-shell';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DataCard, DataCardList } from '@/components/ui/data-card-list';
import { EmployeesTableSkeleton } from '@/components/ui/employees-table-skeleton';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { PositionTag } from '@/components/ui/position-tag';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { employeeStatusBadge, employeeStatusLabel, useEmployeeStatuses } from '@/hooks/use-employee-statuses';
import { useToken } from '@/hooks/use-token';
import { apiFetch } from '@/lib/api';

import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { AlertCircle, Eye, FileBarChart, Pencil, Plus, RefreshCw, ShieldCheck, Users as UsersIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

type Employee = {
    id: string;
    full_name: string;
    doc_number: string;
    email: string;
    phone: string | null;
    position: { id: string; label: string; color: string | null } | null;
    primary_branch: { id: string; name: string } | null;
    vinculation_status: string;
    user_id: string | null;
    archived_at: string | null;
};

type Branch = { id: string; name: string };

type Position = { id: string; label: string; color: string | null };


export default function EmployeesIndex() {
    const token = useToken();
    const employeeStatuses = useEmployeeStatuses();

    const [search, setSearch] = useState('');
    const [branchId, setBranchId] = useState<string>('all');
    const [status, setStatus] = useState<string>('all');
    const [positionId, setPositionId] = useState<string>('all');
    const [includeArchived, setIncludeArchived] = useState(false);

    const params = useMemo(() => {
        const p = new URLSearchParams();
        if (search) p.set('q', search);
        if (branchId !== 'all') p.set('branch_id', branchId);
        if (status !== 'all') p.set('status', status);
        if (positionId !== 'all') p.set('position_id', positionId);
        if (includeArchived) p.set('include_archived', '1');
        return p.toString();
    }, [search, branchId, status, positionId, includeArchived]);

    // Listado principal (Fase 3): query crítica con `keepPreviousData`
    // para no blanquear la tabla al cambiar filtros ni al revisitar. El
    // skeleton completo solo en el primer load sin cache (`isLoading`).
    const employeesQuery = useQuery<Employee[], Error>({
        queryKey: ['employees', 'list', params],
        enabled: !!token,
        placeholderData: keepPreviousData,
        queryFn: async ({ signal }) => {
            const res = await apiFetch(`/api/v1/employees?${params}`, { signal });
            if (!res.ok) {
                throw new Error('No se pudieron cargar los colaboradores.');
            }
            const json = await res.json();
            return (json.data ?? []) as Employee[];
        },
    });

    // Catálogos de los filtros (Fase 3): secciones secundarias e
    // independientes — no bloquean el listado y se cachean (cambian rara vez).
    const branchesQuery = useQuery<Branch[]>({
        queryKey: ['company', 'branches', 'employee-filter'],
        enabled: !!token,
        staleTime: 5 * 60_000,
        queryFn: async ({ signal }) => {
            const res = await apiFetch('/api/v1/company/branches', { signal });
            return res.ok ? (((await res.json()).data ?? []) as Branch[]) : [];
        },
    });

    const positionsQuery = useQuery<Position[]>({
        queryKey: ['employee-positions', 'filter'],
        enabled: !!token,
        staleTime: 5 * 60_000,
        queryFn: async ({ signal }) => {
            const res = await apiFetch('/api/v1/employee-positions', { signal });
            return res.ok ? (((await res.json()).data ?? []) as Position[]) : [];
        },
    });

    const items = employeesQuery.data ?? [];
    const branches = branchesQuery.data ?? [];
    const positions = positionsQuery.data ?? [];
    const loading = employeesQuery.isLoading;
    const refreshing = employeesQuery.isFetching;
    const error = employeesQuery.isError ? (employeesQuery.error?.message ?? 'Error de red al cargar colaboradores.') : null;

    const refresh = () => {
        void employeesQuery.refetch();
        void branchesQuery.refetch();
        void positionsQuery.refetch();
    };

    const headerActions = (
        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <Button variant="outline" size="sm" onClick={refresh} disabled={refreshing} title="Actualizar" className="flex-1 sm:flex-initial">
                <RefreshCw className={`mr-1.5 h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
                Actualizar
            </Button>
            <AppLink href="/employees/reports" className="flex-1 sm:flex-initial">
                <Button variant="outline" size="sm" className="w-full sm:w-auto">
                    <FileBarChart className="mr-1.5 h-4 w-4" />
                    Informes
                </Button>
            </AppLink>
            <AppLink href="/employees/new" className="w-full sm:w-auto">
                <Button size="sm" data-cta="crear-colaborador" data-cta-location="employees-index" className="w-full sm:w-auto">
                    <Plus className="mr-1.5 h-4 w-4" />
                    Crear colaborador
                </Button>
            </AppLink>
        </div>
    );

    return (
        <PageShell title="Colaboradores">
            <div className="w-full max-w-none space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="COLABORADORES"
                    title="Colaboradores"
                    description="Gestiona el equipo HHRR de tu empresa."
                    actions={headerActions}
                />

                <div className="space-y-3">
                    <FilterBar
                        variant="card"
                        searchValue={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Buscar por nombre, documento, email…"
                    >
                        <Select value={branchId} onValueChange={setBranchId}>
                            <SelectTrigger className="w-full sm:w-auto sm:min-w-[160px]">
                                <SelectValue placeholder="Sede" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todas las sedes</SelectItem>
                                {branches.map((b) => (
                                    <SelectItem key={b.id} value={b.id}>
                                        {b.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={positionId} onValueChange={setPositionId}>
                            <SelectTrigger className="w-full sm:w-auto sm:min-w-[160px]">
                                <SelectValue placeholder="Cargo" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todos los cargos</SelectItem>
                                {positions.map((p) => (
                                    <SelectItem key={p.id} value={p.id}>
                                        {p.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={status} onValueChange={setStatus}>
                            <SelectTrigger className="w-full sm:w-auto sm:min-w-[160px]">
                                <SelectValue placeholder="Estado" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todos los estados</SelectItem>
                                {employeeStatuses.statuses.map((k) => (
                                    <SelectItem key={k} value={k}>
                                        {employeeStatuses.labels[k]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FilterBar>

                    <div className="flex items-center gap-2 pl-1">
                        <Checkbox id="employees-include-archived" checked={includeArchived} onCheckedChange={(c) => setIncludeArchived(Boolean(c))} />
                        <Label htmlFor="employees-include-archived" className="text-muted-foreground cursor-pointer text-xs">
                            Incluir colaboradores archivados
                        </Label>
                    </div>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {loading ? (
                    <EmployeesTableSkeleton rows={6} />
                ) : items.length === 0 ? (
                    <Card className="rounded-2xl shadow-sm">
                        <CardContent className="p-0">
                            <EmptyState
                                icon={UsersIcon}
                                title="Sin colaboradores"
                                description="No hay registros con los filtros actuales. Ajusta los criterios o agrega un nuevo colaborador."
                            />
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* Mobile: card-stack — ver FRONTEND_UI_GUIDELINES §10. */}
                        <DataCardList
                            items={items}
                            getKey={(e) => e.id}
                            className="sm:hidden"
                            renderCard={(e) => (
                                <DataCard
                                    title={
                                        <span className="flex items-center gap-2">
                                            <span className="truncate">{e.full_name}</span>
                                            {e.user_id && (
                                                <ShieldCheck
                                                    className="text-muted-foreground h-3.5 w-3.5 shrink-0"
                                                    aria-label="Usuario en el sistema"
                                                />
                                            )}
                                        </span>
                                    }
                                    subtitle={e.email}
                                    fields={[
                                        { label: 'Documento', value: <span className="tabular-nums">{e.doc_number}</span> },
                                        { label: 'Sede', value: e.primary_branch?.name ?? '—' },
                                        {
                                            label: 'Cargo',
                                            value: e.position ? <PositionTag color={e.position.color} label={e.position.label} /> : '—',
                                        },
                                        {
                                            label: 'Estado',
                                            value: (
                                                <Badge variant={employeeStatusBadge(employeeStatuses, e.vinculation_status)}>
                                                    {employeeStatusLabel(employeeStatuses, e.vinculation_status)}
                                                </Badge>
                                            ),
                                        },
                                    ]}
                                    footer={
                                        <div className="ml-auto flex items-center gap-1">
                                            <AppLink href={`/employees/${e.id}`}>
                                                <Button variant="ghost" size="sm">
                                                    <Eye className="mr-1 h-3.5 w-3.5" />
                                                    Ver
                                                </Button>
                                            </AppLink>
                                            <AppLink href={`/employees/${e.id}?edit=1`}>
                                                <Button variant="ghost" size="sm">
                                                    <Pencil className="mr-1 h-3.5 w-3.5" />
                                                    Editar
                                                </Button>
                                            </AppLink>
                                        </div>
                                    }
                                />
                            )}
                        />

                        {/* Desktop: tabla densa */}
                        <Card className="hidden rounded-2xl shadow-sm sm:block">
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Nombre</TableHead>
                                            <TableHead>Documento</TableHead>
                                            <TableHead>Cargo</TableHead>
                                            <TableHead>Sede principal</TableHead>
                                            <TableHead>Estado</TableHead>
                                            <TableHead></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {items.map((e) => (
                                            <TableRow key={e.id}>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">{e.full_name}</span>
                                                        {e.user_id && (
                                                            <ShieldCheck
                                                                className="text-muted-foreground h-3.5 w-3.5"
                                                                aria-label="Usuario en el sistema"
                                                            />
                                                        )}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">{e.email}</div>
                                                </TableCell>
                                                <TableCell className="tabular-nums">{e.doc_number}</TableCell>
                                                <TableCell>
                                                    {e.position ? (
                                                        <PositionTag color={e.position.color} label={e.position.label} />
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>{e.primary_branch?.name ?? '—'}</TableCell>
                                                <TableCell>
                                                    <Badge variant={employeeStatusBadge(employeeStatuses, e.vinculation_status)}>
                                                        {employeeStatusLabel(employeeStatuses, e.vinculation_status)}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <AppLink href={`/employees/${e.id}`}>
                                                            <Button variant="ghost" size="sm">
                                                                <Eye className="mr-1 h-3.5 w-3.5" />
                                                                Ver
                                                            </Button>
                                                        </AppLink>
                                                        <AppLink href={`/employees/${e.id}?edit=1`}>
                                                            <Button variant="ghost" size="sm">
                                                                <Pencil className="mr-1 h-3.5 w-3.5" />
                                                                Editar
                                                            </Button>
                                                        </AppLink>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </PageShell>
    );
}

