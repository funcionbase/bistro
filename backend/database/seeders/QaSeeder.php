<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder de QA.
 *
 * Datos de referencia (Production) + dataset operativo de prueba: empresa
 * Restaurante Flexy con menus, ordenes, cupones, entregas y conversaciones de
 * varias plataformas (WhatsApp, Instagram, Facebook) listas para el panel de
 * chats. Es lo que QA necesita para probar los flujos end-to-end sin tener
 * que registrar nada manualmente.
 *
 * Idempotente: RestauranteFlexySeeder y ChatConversationsDemoSeeder limpian
 * sus datos antes de re-insertar. Se puede correr cuantas veces se quiera.
 *
 * Uso:
 *   php artisan db:seed --class=Database\\Seeders\\QaSeeder --force
 */

//   │           Email           │               Rol               │
//   ├───────────────────────────┼─────────────────────────────────┤
//   │ owner@example.com         │ Propietario                     │
//   ├───────────────────────────┼─────────────────────────────────┤
//   │ admin@example.com         │ Administradora (Carolina Mejía) │
//   ├───────────────────────────┼─────────────────────────────────┤
//   │ kitchen@example.com       │ Cocina (Sebastián Ramírez)      │
//   ├───────────────────────────┼─────────────────────────────────┤
//   │ courier@example.com       │ Domiciliario                    │
class QaSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProductionSeeder::class,
            RestauranteFlexySeeder::class,
            CompanySettingsSeeder::class,
            // Facturación electrónica DIAN. Crea perfil fiscal mínimo
            // + 2 resoluciones (PO, FE) + MockDianProvider activo por empresa.
            // Idempotente — solo escribe lo que falta.
            DianDemoSeeder::class,
            // Flujos DIAN sobre las órdenes históricas demo: siembra
            // documentos DEE POS / FEV / NC con CUFE/CUDE reales para una
            // muestra de las órdenes completed/refunded existentes. Demuestra
            // los escenarios: pos accepted, fev accepted, pos rejected + retry,
            // pos + nota crédito sobre orden refunded, contact con perfil
            // incompleto. Idempotente — skip si ya hay documento para la orden.
            DianFlowsSeeder::class,
            // Escenarios para probar el feed de alertas en /dashboard.
            // Idempotente; depende del dataset de RestauranteFlexySeeder.
            AlertScenariosSeeder::class,
            // Colaboradores y turnos demo. Crea employees para los 4
            // users del equipo demo (owner/admin/kitchen/courier) + 5 sin
            // user_id, y genera turnos para la semana actual con uno activo
            // ahora para que la caja pueda operar en QA sin esperar.
            EmployeesDemoSeeder::class,
            // Empresa adicional en past_due de 2 meses para probar el
            // banner amber con countdown y la vista de comprobante de pago.
            PastDueDemoCompanySeeder::class,
        ]);
    }
}
