<?php

namespace Database\Seeders;

use App\Models\EmployeePosition;
use Illuminate\Database\Seeder;

/**
 * Cargos del sistema. Idempotente: cada deploy garantiza que las
 * posiciones canónicas existen con sus colores. Las posiciones custom las
 * crea cada empresa desde la UI y NO se siembran acá.
 */
class EmployeePositionSeeder extends Seeder
{
    public function run(): void
    {
        $positions = [
            ['slug' => 'waiter', 'label' => 'Mesero/a', 'color' => '#3B82F6'],
            ['slug' => 'cook', 'label' => 'Cocinero/a', 'color' => '#EF4444'],
            ['slug' => 'cashier', 'label' => 'Cajero/a', 'color' => '#10B981'],
            ['slug' => 'bar', 'label' => 'Bartender', 'color' => '#F59E0B'],
            ['slug' => 'manager', 'label' => 'Gerente', 'color' => '#8B5CF6'],
            ['slug' => 'host', 'label' => 'Anfitrión/a', 'color' => '#EC4899'],
            ['slug' => 'cleaning', 'label' => 'Limpieza', 'color' => '#6B7280'],
        ];

        foreach ($positions as $position) {
            EmployeePosition::updateOrCreate(
                ['company_nit' => null, 'slug' => $position['slug']],
                [
                    'label' => $position['label'],
                    'color' => $position['color'],
                    'is_system' => true,
                ]
            );
        }
    }
}
