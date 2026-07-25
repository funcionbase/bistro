import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DashboardPanel } from '@/components/ui/dashboard-panel';
import { FieldHint } from '@/components/ui/field-hint';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useDirtyState } from '@/hooks/use-dirty-state';
import { apiFetch } from '@/lib/api';
import { sanitizePlainText } from '@/lib/input-sanitize';
import { useSharedData } from '@/lib/shared-data';
import { Banknote, Briefcase, Clock, HeartPulse, IdCard, LoaderCircle, Phone } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

export type EmployeeFormValues = {
    primary_branch_id: string;
    position_id: string | null;
    doc_type: string;
    doc_number: string;
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    birth_date: string;
    blood_type: string;
    address: string;
    city: string;
    eps: string;
    arl: string;
    pension_fund: string;
    severance_fund: string;
    bank: string;
    account_type: string;
    account_number: string;
    emergency_contact_name: string;
    emergency_contact_phone: string;
    uniform_size: string;
    contract_type: string;
    base_salary: string;
    pay_type: string;
    pay_rate: string;
    hire_date: string;
    min_days_off_override: string;
};

type Props = {
    initial?: Partial<EmployeeFormValues>;
    onSubmit: (values: EmployeeFormValues) => void | Promise<void>;
    submitting?: boolean;
    submitLabel?: string;
    readOnly?: boolean;
    /**
     * Errores de validación por campo (422 del backend), aplanados a
     * `{ campo: mensaje }`. Se muestran inline bajo cada input, no en un
     * mensaje al pie del formulario.
     */
    errors?: Record<string, string>;
};

type Branch = { id: string; name: string };
type Position = { id: string; label: string };

const defaultValues: EmployeeFormValues = {
    primary_branch_id: '',
    position_id: null,
    doc_type: 'CC',
    doc_number: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    birth_date: '',
    blood_type: '',
    address: '',
    city: '',
    eps: '',
    arl: '',
    pension_fund: '',
    severance_fund: '',
    bank: '',
    account_type: '',
    account_number: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    uniform_size: '',
    contract_type: '',
    base_salary: '',
    pay_type: 'mensual',
    pay_rate: '0',
    hire_date: '',
    min_days_off_override: '',
};

/**
 * Formulario compartido entre crear y editar colaborador. Campos HHRR
 * agrupados en DashboardPanel por sección. Valida sólo en backend (no
 * duplicar reglas).
 */
export default function EmployeeForm({ initial, onSubmit, submitting, submitLabel = 'Guardar', readOnly = false, errors = {} }: Props) {
    const { availableBanks = [] } = useSharedData();
    const [values, setValues] = useState<EmployeeFormValues>({ ...defaultValues, ...initial });
    const [branches, setBranches] = useState<Branch[]>([]);
    const [positions, setPositions] = useState<Position[]>([]);

    // Dirty tracking (#192): compara contra el snapshot inicial para que el
    // BranchSwitcher pida confirmación antes de descartar cambios.
    const initialSnapshotRef = useRef(JSON.stringify({ ...defaultValues, ...initial }));
    useDirtyState(!readOnly && JSON.stringify(values) !== initialSnapshotRef.current, 'employee-form');

    // El catálogo de bancos viene del bootstrap (misma fuente que el enrolamiento
    // y Mi Empresa). La columna `employees.bank` guarda el nombre del banco como
    // texto, así que las opciones usan el nombre como valor. Si el empleado ya
    // tenía un banco que no está en el catálogo, lo conservamos como opción.
    const bankOptions = useMemo(() => {
        const names = availableBanks.map((b) => b.name);
        if (values.bank && !names.includes(values.bank)) {
            return [values.bank, ...names];
        }
        return names;
    }, [availableBanks, values.bank]);

    useEffect(() => {
        (async () => {
            const [brRes, posRes] = await Promise.all([apiFetch('/api/v1/company/branches'), apiFetch('/api/v1/employee-positions')]);
            if (brRes.ok) setBranches((await brRes.json()).data ?? []);
            if (posRes.ok) setPositions((await posRes.json()).data ?? []);
        })();
    }, []);

    const update = <K extends keyof EmployeeFormValues>(key: K, value: EmployeeFormValues[K]) => {
        setValues((v) => ({ ...v, [key]: value }));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const payload = { ...values };
        if (payload.position_id === '') payload.position_id = null;
        onSubmit(payload);
    };

    return (
        <form noValidate onSubmit={handleSubmit}>
            <fieldset disabled={readOnly} className="space-y-6 disabled:cursor-default">
            <DashboardPanel title="Identidad" icon={IdCard}>
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-doc-type">Tipo de documento</Label>
                        <Select value={values.doc_type} onValueChange={(v) => update('doc_type', v)}>
                            <SelectTrigger id="employee-doc-type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="CC">Cédula de ciudadanía</SelectItem>
                                <SelectItem value="CE">Cédula de extranjería</SelectItem>
                                <SelectItem value="PA">Pasaporte</SelectItem>
                                <SelectItem value="PEP">Permiso especial</SelectItem>
                                <SelectItem value="TI">Tarjeta de identidad</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-doc-number">
                            Número documento <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="employee-doc-number"
                            value={values.doc_number}
                            onChange={(e) => update('doc_number', e.target.value)}
                            maxLength={32}
                            required
                        />
                        <InputError message={errors.doc_number} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-birth-date">Fecha nacimiento</Label>
                        <Input
                            id="employee-birth-date"
                            type="date"
                            value={values.birth_date}
                            onChange={(e) => update('birth_date', e.target.value)}
                        />
                        <InputError message={errors.birth_date} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-first-name">
                            Nombres <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="employee-first-name"
                            value={values.first_name}
                            onChange={(e) => update('first_name', sanitizePlainText(e.target.value, 120, false, false))}
                            maxLength={120}
                            required
                        />
                        <InputError message={errors.first_name} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-last-name">
                            Apellidos <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="employee-last-name"
                            value={values.last_name}
                            onChange={(e) => update('last_name', sanitizePlainText(e.target.value, 120, false, false))}
                            maxLength={120}
                            required
                        />
                        <InputError message={errors.last_name} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-blood-type">Tipo de sangre</Label>
                        <Select value={values.blood_type || 'none'} onValueChange={(v) => update('blood_type', v === 'none' ? '' : v)}>
                            <SelectTrigger id="employee-blood-type">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">—</SelectItem>
                                {['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'].map((t) => (
                                    <SelectItem key={t} value={t}>
                                        {t}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </DashboardPanel>

            <DashboardPanel title="Contacto" icon={Phone}>
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-email">
                            Email <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="employee-email"
                            type="email"
                            value={values.email}
                            onChange={(e) => update('email', e.target.value)}
                            maxLength={180}
                            required
                        />
                        <InputError message={errors.email} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-phone">Teléfono</Label>
                        <Input id="employee-phone" value={values.phone} onChange={(e) => update('phone', e.target.value)} maxLength={30} />
                        <InputError message={errors.phone} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-city">Ciudad</Label>
                        <Input
                            id="employee-city"
                            value={values.city}
                            onChange={(e) => update('city', sanitizePlainText(e.target.value, 120, false, false))}
                            maxLength={120}
                        />
                        <InputError message={errors.city} />
                    </div>
                    <div className="space-y-1.5 md:col-span-2">
                        <Label htmlFor="employee-address">Dirección</Label>
                        <Input
                            id="employee-address"
                            value={values.address}
                            onChange={(e) => update('address', sanitizePlainText(e.target.value, 255, false, false))}
                            maxLength={255}
                        />
                        <InputError message={errors.address} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-uniform-size">Talla de uniforme</Label>
                        <Input
                            id="employee-uniform-size"
                            value={values.uniform_size}
                            onChange={(e) => update('uniform_size', sanitizePlainText(e.target.value, 20, false, false))}
                            maxLength={20}
                        />
                        <InputError message={errors.uniform_size} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-emergency-name">Contacto emergencia</Label>
                        <Input
                            id="employee-emergency-name"
                            value={values.emergency_contact_name}
                            onChange={(e) => update('emergency_contact_name', sanitizePlainText(e.target.value, 120, false, false))}
                            maxLength={120}
                        />
                        <InputError message={errors.emergency_contact_name} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-emergency-phone">Teléfono emergencia</Label>
                        <Input
                            id="employee-emergency-phone"
                            value={values.emergency_contact_phone}
                            onChange={(e) => update('emergency_contact_phone', e.target.value)}
                            maxLength={30}
                        />
                        <InputError message={errors.emergency_contact_phone} />
                    </div>
                </div>
            </DashboardPanel>

            <DashboardPanel title="Cargo y sede" icon={Briefcase}>
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-branch">
                            Sede principal <span className="text-destructive">*</span>
                        </Label>
                        <Select value={values.primary_branch_id} onValueChange={(v) => update('primary_branch_id', v)}>
                            <SelectTrigger id="employee-branch">
                                <SelectValue placeholder="Selecciona sede" />
                            </SelectTrigger>
                            <SelectContent>
                                {branches.map((b) => (
                                    <SelectItem key={b.id} value={b.id}>
                                        {b.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.primary_branch_id} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-position">Cargo</Label>
                        <Select value={values.position_id ?? 'none'} onValueChange={(v) => update('position_id', v === 'none' ? null : v)}>
                            <SelectTrigger id="employee-position">
                                <SelectValue placeholder="Sin cargo" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">Sin cargo</SelectItem>
                                {positions.map((p) => (
                                    <SelectItem key={p.id} value={p.id}>
                                        {p.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </DashboardPanel>

            <DashboardPanel title="Seguridad social" icon={HeartPulse}>
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-eps">EPS</Label>
                        <Input
                            id="employee-eps"
                            value={values.eps}
                            onChange={(e) => update('eps', sanitizePlainText(e.target.value, 120, false, false))}
                            maxLength={120}
                        />
                        <InputError message={errors.eps} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-arl">ARL</Label>
                        <Input
                            id="employee-arl"
                            value={values.arl}
                            onChange={(e) => update('arl', sanitizePlainText(e.target.value, 120, false, false))}
                            maxLength={120}
                        />
                        <InputError message={errors.arl} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-pension">Fondo de pensiones</Label>
                        <Input
                            id="employee-pension"
                            value={values.pension_fund}
                            onChange={(e) => update('pension_fund', sanitizePlainText(e.target.value, 120, false, false))}
                            maxLength={120}
                        />
                        <InputError message={errors.pension_fund} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-severance">Cesantías</Label>
                        <Input
                            id="employee-severance"
                            value={values.severance_fund}
                            onChange={(e) => update('severance_fund', sanitizePlainText(e.target.value, 120, false, false))}
                            maxLength={120}
                        />
                        <InputError message={errors.severance_fund} />
                    </div>
                </div>
            </DashboardPanel>

            <DashboardPanel title="Pago" icon={Banknote}>
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-contract-type">Tipo contrato</Label>
                        <Select value={values.contract_type || 'none'} onValueChange={(v) => update('contract_type', v === 'none' ? '' : v)}>
                            <SelectTrigger id="employee-contract-type">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">—</SelectItem>
                                <SelectItem value="fijo">Término fijo</SelectItem>
                                <SelectItem value="indefinido">Indefinido</SelectItem>
                                <SelectItem value="OPS">OPS</SelectItem>
                                <SelectItem value="aprendizaje">Aprendizaje</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-pay-type">
                            Tipo de pago <span className="text-destructive">*</span>
                        </Label>
                        <Select value={values.pay_type} onValueChange={(v) => update('pay_type', v)}>
                            <SelectTrigger id="employee-pay-type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="hora">Por hora</SelectItem>
                                <SelectItem value="diario">Diario</SelectItem>
                                <SelectItem value="semanal">Semanal</SelectItem>
                                <SelectItem value="quincenal">Quincenal</SelectItem>
                                <SelectItem value="mensual">Mensual</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-pay-rate" className="flex items-center gap-1.5">
                            Tarifa (COP) <span className="text-destructive">*</span>
                            <FieldHint text="Valor que se paga por la unidad del 'Tipo de pago' elegido. Ej: si el tipo es 'Por hora', es el valor de la hora; si es 'Mensual', el salario mensual. Es la base con la que se estima el costo de nómina en los reportes." />
                        </Label>
                        <Input
                            id="employee-pay-rate"
                            type="number"
                            min="0"
                            step="1"
                            value={values.pay_rate}
                            onChange={(e) => update('pay_rate', e.target.value)}
                            required
                        />
                        <InputError message={errors.pay_rate} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-bank">Banco</Label>
                        <Select value={values.bank || 'none'} onValueChange={(v) => update('bank', v === 'none' ? '' : v)}>
                            <SelectTrigger id="employee-bank">
                                <SelectValue placeholder="Selecciona un banco" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">—</SelectItem>
                                {bankOptions.map((name) => (
                                    <SelectItem key={name} value={name}>
                                        {name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-account-type">Tipo de cuenta</Label>
                        <Select value={values.account_type || 'none'} onValueChange={(v) => update('account_type', v === 'none' ? '' : v)}>
                            <SelectTrigger id="employee-account-type">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">—</SelectItem>
                                <SelectItem value="ahorros">Ahorros</SelectItem>
                                <SelectItem value="corriente">Corriente</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5 md:col-span-2">
                        <Label htmlFor="employee-account-number">Número de cuenta</Label>
                        <Input
                            id="employee-account-number"
                            value={values.account_number}
                            onChange={(e) => update('account_number', e.target.value)}
                            maxLength={32}
                        />
                        <InputError message={errors.account_number} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-hire-date">Fecha de ingreso</Label>
                        <Input id="employee-hire-date" type="date" value={values.hire_date} onChange={(e) => update('hire_date', e.target.value)} />
                        <InputError message={errors.hire_date} />
                    </div>
                </div>
            </DashboardPanel>

            <DashboardPanel title="Jornada" icon={Clock}>
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="space-y-1.5">
                        <Label htmlFor="employee-min-days-off">Días libres mínimos (override)</Label>
                        <Input
                            id="employee-min-days-off"
                            type="number"
                            min="0"
                            max="7"
                            value={values.min_days_off_override}
                            onChange={(e) => update('min_days_off_override', e.target.value)}
                            placeholder="usa el de empresa si vacío"
                        />
                        <InputError message={errors.min_days_off_override} />
                    </div>
                </div>
            </DashboardPanel>

            {!readOnly && (
                <div className="flex justify-end">
                    <Button type="submit" disabled={submitting} className="min-w-32">
                        {submitting ? <LoaderCircle className="h-4 w-4 animate-spin" /> : submitLabel}
                    </Button>
                </div>
            )}
            </fieldset>
        </form>
    );
}
