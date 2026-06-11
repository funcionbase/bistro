<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\EmployeeShift;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo data de colaboradores y turnos para QA.
 *
 * Crea perfiles `employees` para los 4 users del equipo demo de
 * `RestauranteFlexySeeder` (owner, admin, kitchen, courier) — invariante
 * del módulo: todo user con `company_user.role` operativo debe tener su
 * fila en `employees`, sino los flujos de caja/turno se rompen.
 *
 * Además crea ~5 colaboradores SIN `user_id` (perfil HHRR sin acceso al
 * sistema: cocineros auxiliares, limpieza, mesero, bartender) para
 * probar el listado y el filtrado.
 *
 * Turnos: 7 días alrededor de hoy en la sede principal (Pereira). El
 * intervalo de "hoy" cubre la hora actual para que el guard de caja
 * (`ShiftActiveGuardService`) permita abrir caja sin tener que esperar.
 *
 * Idempotente: usa `updateOrCreate` por `(company_nit, doc_number)` y
 * limpia turnos del rango antes de re-insertar para no duplicar al
 * correr el seeder varias veces.
 */
class EmployeesDemoSeeder extends Seeder
{
    private const COMPANY_NIT = '1';

    public function run(): void
    {
        $branchPereira = Branch::query()
            ->where('company_nit', self::COMPANY_NIT)
            ->where('slug', 'pereira')
            ->first();

        $branchCartago = Branch::query()
            ->where('company_nit', self::COMPANY_NIT)
            ->where('slug', 'cartago')
            ->first();

        if (! $branchPereira) {
            // RestauranteFlexySeeder no ha corrido o cambió de slug — abortamos
            // silenciosamente para no romper el orden del QaSeeder.
            return;
        }

        $positions = $this->resolvePositions();

        // 1) Crear/actualizar employees para los 4 users del demo team.
        $employeesByUser = $this->upsertEmployeesForExistingUsers(
            $branchPereira,
            $branchCartago ?? $branchPereira,
            $positions
        );

        // 2) Crear ~5 employees SIN user (perfil HHRR puro).
        $standaloneEmployees = $this->upsertStandaloneEmployees($branchPereira, $positions);

        // 3) Generar turnos para la semana actual incluyendo uno "activo ahora"
        //    para los users del equipo (asegura que la caja funcione en QA).
        $this->seedShiftsForWeek(
            array_merge(array_values($employeesByUser), $standaloneEmployees),
            $branchPereira->id,
        );
    }

    /**
     * @return array<string, EmployeePosition>
     */
    private function resolvePositions(): array
    {
        return EmployeePosition::query()
            ->where('is_system', true)
            ->get()
            ->keyBy('slug')
            ->all();
    }

    /**
     * @param  array<string, EmployeePosition>  $positions
     * @return array<string, Employee>
     */
    private function upsertEmployeesForExistingUsers(Branch $pereira, Branch $cartago, array $positions): array
    {
        $owner = User::where('email', 'cristianmarint@gmail.com')->first();
        $admin = User::where('email', 'flexyflowco@gmail.com')->first();
        $kitchen = User::where('email', 'flexyconsultora@gmail.com')->first();
        $courier = User::where('email', 'cristianmarintt@gmail.com')->first();

        $blueprints = [
            'owner' => [
                'user' => $owner,
                'position' => $positions['manager'] ?? null,
                'doc_number' => '1010100001',
                'first_name' => 'Cristian',
                'last_name' => 'Marín',
                'email' => 'cristianmarint@gmail.com',
                'pay_type' => 'mensual',
                'pay_rate' => 8000000,
                'contract_type' => 'indefinido',
                'branch_id' => $pereira->id,
            ],
            'admin' => [
                'user' => $admin,
                'position' => $positions['manager'] ?? null,
                'doc_number' => '1010100002',
                'first_name' => 'Carolina',
                'last_name' => 'Mejía',
                'email' => 'flexyflowco@gmail.com',
                'pay_type' => 'mensual',
                'pay_rate' => 4500000,
                'contract_type' => 'indefinido',
                'branch_id' => $pereira->id,
            ],
            'kitchen' => [
                'user' => $kitchen,
                'position' => $positions['cook'] ?? null,
                'doc_number' => '1010100003',
                'first_name' => 'Sebastián',
                'last_name' => 'Ramírez',
                'email' => 'flexyconsultora@gmail.com',
                'pay_type' => 'mensual',
                'pay_rate' => 1800000,
                'contract_type' => 'fijo',
                'branch_id' => $pereira->id,
            ],
            'courier' => [
                'user' => $courier,
                'position' => $positions['waiter'] ?? null,
                'doc_number' => '1010100004',
                'first_name' => 'Andrés',
                'last_name' => 'Domiciliario',
                'email' => 'cristianmarintt@gmail.com',
                'pay_type' => 'diario',
                'pay_rate' => 60000,
                'contract_type' => 'OPS',
                'branch_id' => $cartago->id,
            ],
        ];

        $result = [];

        foreach ($blueprints as $key => $bp) {
            if (! $bp['user']) {
                continue;
            }

            $employee = Employee::updateOrCreate(
                [
                    'company_nit' => self::COMPANY_NIT,
                    'doc_number' => $bp['doc_number'],
                ],
                [
                    'user_id' => $bp['user']->id,
                    'primary_branch_id' => $bp['branch_id'],
                    'position_id' => $bp['position']?->id,
                    'doc_type' => 'CC',
                    'first_name' => $bp['first_name'],
                    'last_name' => $bp['last_name'],
                    'email' => $bp['email'],
                    'phone' => '3001000000',
                    'eps' => 'EPS Sura',
                    'arl' => 'ARL Positiva',
                    'pension_fund' => 'Protección',
                    'severance_fund' => 'Protección',
                    'bank' => 'Bancolombia',
                    'account_type' => 'ahorros',
                    'account_number' => '123456789',
                    'emergency_contact_name' => 'Familiar',
                    'emergency_contact_phone' => '3009999999',
                    'contract_type' => $bp['contract_type'],
                    'pay_type' => $bp['pay_type'],
                    'pay_rate' => $bp['pay_rate'],
                    'hire_date' => Carbon::now()->subYear()->toDateString(),
                    'vinculation_status' => 'active',
                ]
            );

            $result[$key] = $employee;
        }

        return $result;
    }

    /**
     * @param  array<string, EmployeePosition>  $positions
     * @return array<int, Employee>
     */
    private function upsertStandaloneEmployees(Branch $branch, array $positions): array
    {
        $blueprints = [
            [
                'doc_number' => '1020200001',
                'first_name' => 'Laura',
                'last_name' => 'Gómez',
                'email' => 'laura.gomez@demo.flexyflow',
                'position' => $positions['waiter'] ?? null,
                'pay_type' => 'hora',
                'pay_rate' => 7500,
            ],
            [
                'doc_number' => '1020200002',
                'first_name' => 'Diego',
                'last_name' => 'Patiño',
                'email' => 'diego.patino@demo.flexyflow',
                'position' => $positions['cook'] ?? null,
                'pay_type' => 'mensual',
                'pay_rate' => 1500000,
            ],
            [
                'doc_number' => '1020200003',
                'first_name' => 'Camila',
                'last_name' => 'Restrepo',
                'email' => 'camila.restrepo@demo.flexyflow',
                'position' => $positions['cashier'] ?? null,
                'pay_type' => 'mensual',
                'pay_rate' => 1500000,
            ],
            [
                'doc_number' => '1020200004',
                'first_name' => 'Mateo',
                'last_name' => 'Vargas',
                'email' => 'mateo.vargas@demo.flexyflow',
                'position' => $positions['bar'] ?? null,
                'pay_type' => 'hora',
                'pay_rate' => 8500,
            ],
            [
                'doc_number' => '1020200005',
                'first_name' => 'María',
                'last_name' => 'Quintero',
                'email' => 'maria.quintero@demo.flexyflow',
                'position' => $positions['cleaning'] ?? null,
                'pay_type' => 'diario',
                'pay_rate' => 55000,
            ],
        ];

        $employees = [];

        foreach ($blueprints as $bp) {
            $employees[] = Employee::updateOrCreate(
                [
                    'company_nit' => self::COMPANY_NIT,
                    'doc_number' => $bp['doc_number'],
                ],
                [
                    'user_id' => null,
                    'primary_branch_id' => $branch->id,
                    'position_id' => $bp['position']?->id,
                    'doc_type' => 'CC',
                    'first_name' => $bp['first_name'],
                    'last_name' => $bp['last_name'],
                    'email' => $bp['email'],
                    'phone' => '3001234567',
                    'eps' => 'EPS Sanitas',
                    'arl' => 'ARL Sura',
                    'pension_fund' => 'Colpensiones',
                    'severance_fund' => 'Porvenir',
                    'bank' => 'Davivienda',
                    'account_type' => 'ahorros',
                    'account_number' => '987654321',
                    'emergency_contact_name' => 'Familiar',
                    'emergency_contact_phone' => '3019999999',
                    'contract_type' => $bp['pay_type'] === 'hora' || $bp['pay_type'] === 'diario' ? 'OPS' : 'fijo',
                    'pay_type' => $bp['pay_type'],
                    'pay_rate' => $bp['pay_rate'],
                    'hire_date' => Carbon::now()->subMonths(6)->toDateString(),
                    'vinculation_status' => 'active',
                ]
            );
        }

        return $employees;
    }

    /**
     * Genera turnos para la semana actual (lunes → domingo en TZ Bogotá).
     *
     * Para cada colaborador se asigna un turno típico de operación
     * (10:00-18:00 días laborales, 11:00-20:00 sáb/dom). El "hoy" se
     * extiende hacia atrás y adelante respecto a NOW() para que el guard
     * de caja encuentre un turno activo si la prueba se hace en horario
     * laboral. Si es de madrugada, igual queda registrado pero no se
     * podrá operar caja sin owner (esperado).
     *
     * @param  list<Employee>  $employees
     */
    private function seedShiftsForWeek(array $employees, string $branchId): void
    {
        $tz = config('app.timezone', 'America/Bogota');
        $now = Carbon::now($tz);
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(7);

        // Limpieza idempotente: borrar turnos previos del seeder en este rango
        // para los employees demo. NO tocamos turnos de empleados creados
        // manualmente por QA.
        $ids = array_map(fn (Employee $e) => $e->id, $employees);
        DB::table('employee_shifts')
            ->whereIn('employee_id', $ids)
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<', $weekEnd)
            ->delete();

        $createdById = $employees[0]->user_id ?? null;

        foreach ($employees as $idx => $employee) {
            for ($d = 0; $d < 7; $d++) {
                $day = $weekStart->copy()->addDays($d);

                // Skip aleatorio: ~1 día libre por empleado por semana para
                // que el reporte muestre distribución no uniforme.
                if ($d === (intval($employee->doc_number, 10) % 7)) {
                    continue;
                }

                $isWeekend = in_array($day->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true);
                $shiftStart = $isWeekend
                    ? $day->copy()->setTime(11, 0)
                    : $day->copy()->setTime(10, 0);
                $shiftEnd = $isWeekend
                    ? $day->copy()->setTime(20, 0)
                    : $day->copy()->setTime(18, 0);

                // Para el "hoy", ajustar las horas alrededor de NOW() para
                // garantizar que haya turno activo durante pruebas — solo
                // para los primeros 3 employees (suficiente para abrir caja).
                if ($day->isSameDay($now) && $idx < 3) {
                    $shiftStart = $now->copy()->subHours(2);
                    $shiftEnd = $now->copy()->addHours(6);
                }

                EmployeeShift::create([
                    'employee_id' => $employee->id,
                    'branch_id' => $branchId,
                    'starts_at' => $shiftStart,
                    'ends_at' => $shiftEnd,
                    'status' => 'scheduled',
                    'created_by_user_id' => $createdById,
                ]);
            }
        }
    }
}
