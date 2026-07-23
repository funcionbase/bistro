<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\PermissionTemplate;
use Illuminate\Database\Seeder;

class PermissionTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $features = Feature::all();
        $employeeAllowed = config('roles.default_employee_permissions', ['orders.read', 'chats.read']);

        // Permisos operativos para roles del flujo de mesa con QR.
        // Mapeo explícito por feature.slug → [can_create, can_read, can_update, can_delete].
        // Cualquier slug no listado queda en [false,false,false,false].
        //
        //  - waiter: pantalla del mesero — aprobar/rechazar tandas (orders.update),
        //    editar notas, resolver cancellation_requests, ver clientes para
        //    identificar comensales recurrentes, ver/responder chats.
        //  - cook: solo KDS — ver órdenes y actualizar status de items
        //    (orders.update) para mover entre estados.
        //  - cashier: caja con pago dividido — ver órdenes, actualizar
        //    (closeWithPayment, refund) y ver reportes propios.
        $waiterMap = [
            'orders.read' => [false, true, false, false],
            'orders.update' => [false, false, true, false],
            'menu.read' => [false, true, false, false],
            'chats.read' => [false, true, false, false],
            'chats.update' => [false, false, true, false],
            'clients.read' => [false, true, false, false],
            'clients.update' => [false, false, true, false],
            'hours.read' => [false, true, false, false],
            // Mesero ve el KDS consolidado pero no opera.
            'kds.read' => [false, true, false, false],
            // Mesero imprime/reimprime tirilla DIAN + ve el listado
            // de su sede para reimprimir tirillas perdidas / consultar
            // estado del documento de la mesa que atendió.
            'dian.documents.read' => [false, true, false, false],
            'dian.print' => [false, false, true, false],
        ];
        $cookMap = [
            'orders.read' => [false, true, false, false],
            'orders.update' => [false, false, true, false],
            'menu.read' => [false, true, false, false],
            // El cocinero es el operador principal del KDS.
            'kds.read' => [false, true, false, false],
            'kds.update' => [false, false, true, false],
        ];
        $cashierMap = [
            // orders.create habilita abrir caja (cash-register/open exige
            // `orders.create,create`) y la pantalla POS (crear órdenes walk-in).
            // can_read=true además hace visible el ítem "Caja" en el sidebar
            // (gate `permission: 'orders.create'`). Sin esto el Cajero ni ve ni
            // puede operar la caja — justo su función principal.
            'orders.create' => [true, true, false, false],
            'orders.read' => [false, true, false, false],
            'orders.update' => [false, false, true, false],
            'menu.read' => [false, true, false, false],
            'reports.read' => [false, true, false, false],
            'clients.read' => [false, true, false, false],
            // Cajero ve el estado de la cocina (no opera).
            'kds.read' => [false, true, false, false],
            // Cajero emite documentos DIAN al cobrar, ve listados de
            // su sede, completa perfiles fiscales de contactos al vuelo,
            // imprime/reimprime tirilla.
            'dian.documents.read' => [false, true, false, false],
            'dian.documents.emit' => [true, false, false, false],
            'dian.recipients.read' => [false, true, false, false],
            'dian.recipients.write' => [false, false, true, false],
            'dian.print' => [false, false, true, false],
        ];
        // Domiciliario "courier-only": sólo ve y toma sus propias entregas.
        // NO recibe orders.read/menu.read/reports.read ni ningún FULL_NAV
        // permission — esa selección es justo la que activa el "courier mode"
        // del sidebar (solo "Mis entregas") + redirect a /my-deliveries.
        // Ver App\Support\PostLoginRedirect y lib/courier-mode.ts.
        $courierMap = [
            'deliveries.read' => [false, true, false, false],
            'deliveries.self_assign' => [false, true, false, false],
            'deliveries.update' => [false, false, true, false],
        ];

        // Roles operativos administrativos. Mismo modelo que los
        // del flujo de mesa: is_system=false, asignables/renombrables.
        // Sembrables vía `php artisan roles:sync-templates`.
        //
        //  - manager: gerente operativo de una sede — ve todo el día a día
        //    sin tocar contabilidad/empresa. Cierra órdenes, ajusta menú,
        //    gestiona turnos, mueve inventario en su sede.
        //  - accountant: contador externo o interno — lectura financiera
        //    cross-sede (metrics.view_all_branches), sin mutaciones.
        //  - marketing: gestiona cupones + loyalty + clientes + chats.
        //    Sin acceso a contabilidad ni operación.
        //  - inventory_manager: bodeguero — CRUD sobre inventory,
        //    suppliers, purchases (sin pagar ni anular), warehouses. SIN
        //    transfer_cross_branch (operación sensible cross-sede).
        //  - supervisor: read-mostly operativo — turno entrante revisa
        //    estado general sin mutar configuración.
        $managerMap = [
            'orders.read' => [false, true, false, false],
            'orders.update' => [false, false, true, false],
            'menu.read' => [false, true, false, false],
            'menu.update' => [false, false, true, false],
            'hours.read' => [false, true, false, false],
            'hours.update' => [false, false, true, false],
            'shifts.read' => [false, true, false, false],
            'shifts.manage' => [true, true, true, true],
            'inventory.read' => [false, true, false, false],
            'inventory.update' => [false, false, true, false],
            'reports.read' => [false, true, false, false],
            'chats.read' => [false, true, false, false],
            'chats.update' => [false, false, true, false],
            'clients.read' => [false, true, false, false],
            'clients.update' => [false, false, true, false],
            'employees.read' => [false, true, false, false],
            'coupons.read' => [false, true, false, false],
            'loyalty.read' => [false, true, false, false],
            // Gerente operativo: ve y opera KDS de su sede; gestión
            // de estaciones (kds_stations.*) se queda owner-only por default.
            'kds.read' => [false, true, false, false],
            'kds.update' => [false, false, true, false],
            // Manager respalda a caja: emite documentos DIAN, opera
            // nota crédito y reintentos, completa perfiles de contactos.
            // NO toca config.write ni default_recipient.write (owner-only).
            'dian.documents.read' => [false, true, false, false],
            'dian.documents.emit' => [true, false, false, false],
            'dian.documents.credit_note' => [true, false, false, false],
            'dian.documents.retry' => [false, false, true, false],
            'dian.recipients.read' => [false, true, false, false],
            'dian.recipients.write' => [false, false, true, false],
            'dian.print' => [false, false, true, false],
        ];
        $accountantMap = [
            'orders.read' => [false, true, false, false],
            'reports.read' => [false, true, false, false],
            'billing.read' => [false, true, false, false],
            'metrics.view_all_branches' => [false, true, false, false],
            'purchases.read' => [false, true, false, false],
            'suppliers.read' => [false, true, false, false],
            'employees.read' => [false, true, false, false],
            'employees.view_salary' => [false, true, false, false],
            'workforce.reports' => [false, true, false, false],
            'coupons.read' => [false, true, false, false],
            'loyalty.read' => [false, true, false, false],
            'inventory.read' => [false, true, false, false],
            // Contador: lectura completa de configuración y documentos
            // DIAN para conciliación contable (sin escribir nada).
            'dian.config.read' => [false, true, false, false],
            'dian.documents.read' => [false, true, false, false],
            'dian.recipients.read' => [false, true, false, false],
        ];
        $marketingMap = [
            'coupons.read' => [false, true, false, false],
            'coupons.create' => [true, false, false, false],
            'coupons.update' => [false, false, true, false],
            'loyalty.read' => [false, true, false, false],
            'loyalty.update' => [false, false, true, false],
            'clients.read' => [false, true, false, false],
            'clients.update' => [false, false, true, false],
            'chats.read' => [false, true, false, false],
            'chats.update' => [false, false, true, false],
        ];
        $inventoryManagerMap = [
            'inventory.read' => [false, true, false, false],
            'inventory.create' => [true, false, false, false],
            'inventory.update' => [false, false, true, false],
            'inventory.delete' => [false, false, false, true],
            'warehouses.manage' => [true, true, true, true],
            'suppliers.read' => [false, true, false, false],
            'suppliers.create' => [true, false, false, false],
            'suppliers.update' => [false, false, true, false],
            'purchases.read' => [false, true, false, false],
            'purchases.create' => [true, false, false, false],
            'purchases.update' => [false, false, true, false],
            'purchases.receive' => [false, false, true, false],
        ];
        $supervisorMap = [
            'orders.read' => [false, true, false, false],
            'orders.update' => [false, false, true, false],
            'deliveries.read' => [false, true, false, false],
            'deliveries.update' => [false, false, true, false],
            'shifts.read' => [false, true, false, false],
            'inventory.read' => [false, true, false, false],
            'chats.read' => [false, true, false, false],
            'reports.read' => [false, true, false, false],
            'employees.read' => [false, true, false, false],
            'hours.read' => [false, true, false, false],
            'clients.read' => [false, true, false, false],
            // Supervisor entra a verificar el KDS; opera si hace falta
            // cubrir al cocinero. Gestión de estaciones se queda con owner.
            'kds.read' => [false, true, false, false],
            'kds.update' => [false, false, true, false],
            // Supervisor refuerza a caja en horas pico: lee documentos,
            // emite y reimprime tirilla. Sin nota crédito (acción sensible que
            // queda con manager/admin/owner).
            'dian.documents.read' => [false, true, false, false],
            'dian.documents.emit' => [true, false, false, false],
            'dian.recipients.read' => [false, true, false, false],
            'dian.print' => [false, false, true, false],
        ];

        $roleTypes = ['owner', 'admin', 'employee', 'waiter', 'cook', 'cashier', 'courier', 'manager', 'accountant', 'marketing', 'inventory_manager', 'supervisor'];

        foreach ($roleTypes as $roleType) {
            foreach ($features as $feature) {
                $slug = $feature->slug;
                $group = strtok($slug, '.');

                // Notificaciones push: self-service universal. Cada
                // user controla sus propias suscripciones (notifications.*),
                // independiente del rol. El sistema decide a quién enviar
                // push según permisos operativos (orders.update,
                // reports.read), NO según notifications.*. Por eso aquí
                // [true, true, true, true] para todos los role_type.
                if (str_starts_with($slug, 'notifications.')) {
                    PermissionTemplate::updateOrCreate(
                        ['role_type' => $roleType, 'feature_id' => $feature->id],
                        [
                            'can_create' => true,
                            'can_read' => true,
                            'can_update' => true,
                            'can_delete' => true,
                        ]
                    );

                    continue;
                }

                $perms = match ($roleType) {
                    'owner' => [true, true, true, true],
                    'admin' => match (true) {
                        // swap_phone y disconnect son SOLO owner. No-degradables.
                        in_array($slug, ['whatsapp.swap_phone', 'whatsapp.disconnect'], true) => [false, false, false, false],
                        // Perfil fiscal del emisor: owner-only. El admin bypassea
                        // por is_system en runtime, pero el template lo declara
                        // sin permiso para que la intención (owner-only) quede
                        // explícita en el editor de permisos y para roles futuros.
                        $slug === 'company.fiscal_profile' => [false, false, false, false],
                        // connect / update WhatsApp delegables a admin (default true)
                        $slug === 'whatsapp.connect' => [true, true, false, false],
                        $slug === 'whatsapp.update' => [false, true, true, false],
                        $slug === 'whatsapp.read' => [false, true, false, false],
                        // Auditoría de chats: el admin supervisa (lectura), no
                        // administra la pista de auditoría.
                        $slug === 'chats.audit' => [false, true, false, false],
                        // Aislamiento por sede. Owner-only por default,
                        // asignables manualmente vía editor de permisos. NO se
                        // otorgan a admin automáticamente — son acciones
                        // sensibles (cross-sede o bypass de validación).
                        // kds_stations.* entra al mismo bucket — la
                        // gestión de estaciones + tokens es sensible (afecta
                        // dispositivos físicos en cocina).
                        in_array($slug, [
                            'chats.reassign_branch',
                            'whatsapp.manage_branch_channels',
                            'cash_register.bypass_switch_lock',
                            'cash_register.manage',
                            'cash_register.operate_others',
                            'inventory.transfer_cross_branch',
                            'kds_stations.read',
                            'kds_stations.create',
                            'kds_stations.update',
                            'kds_stations.delete',
                        ], true) => [false, false, false, false],
                        // DIAN: admin recibe la mayoría EXCEPTO
                        // dian.config.write (cambiar credenciales del
                        // provider) y dian.default_recipient.write (cambiar
                        // adquirente por defecto) — sensibles, owner-only.
                        $slug === 'dian.config.read' => [false, true, false, false],
                        $slug === 'dian.config.write' => [false, false, false, false],
                        $slug === 'dian.default_recipient.write' => [false, false, false, false],
                        $slug === 'dian.documents.read' => [false, true, false, false],
                        $slug === 'dian.documents.emit' => [true, false, false, false],
                        $slug === 'dian.documents.credit_note' => [true, false, false, false],
                        $slug === 'dian.documents.retry' => [false, false, true, false],
                        $slug === 'dian.recipients.read' => [false, true, false, false],
                        $slug === 'dian.recipients.write' => [false, false, true, false],
                        $slug === 'dian.print' => [false, false, true, false],
                        default => [
                            true,
                            true,
                            true,
                            in_array($group, ['roles', 'users'], true) ? false : true,
                        ],
                    },
                    'employee' => match (true) {
                        // Owner-only: nunca para employee.
                        in_array($slug, ['whatsapp.swap_phone', 'whatsapp.disconnect', 'whatsapp.connect', 'whatsapp.update'], true) => [false, false, false, false],
                        // El colaborador ve sus turnos en /me/agenda. can_read=true
                        // hace que `shifts.read` aparezca en el permissions del JWT y el
                        // frontend habilite la ruta /me/agenda. Las mutaciones de turnos
                        // siguen bloqueadas (manage/suggest no se otorgan a employee).
                        $slug === 'shifts.read' => [false, true, false, false],
                        default => [
                            false,
                            in_array($slug, $employeeAllowed, true),
                            false,
                            false,
                        ],
                    },
                    'waiter' => $waiterMap[$slug] ?? [false, false, false, false],
                    'cook' => $cookMap[$slug] ?? [false, false, false, false],
                    'cashier' => $cashierMap[$slug] ?? [false, false, false, false],
                    'courier' => $courierMap[$slug] ?? [false, false, false, false],
                    'manager' => $managerMap[$slug] ?? [false, false, false, false],
                    'accountant' => $accountantMap[$slug] ?? [false, false, false, false],
                    'marketing' => $marketingMap[$slug] ?? [false, false, false, false],
                    'inventory_manager' => $inventoryManagerMap[$slug] ?? [false, false, false, false],
                    'supervisor' => $supervisorMap[$slug] ?? [false, false, false, false],
                };

                PermissionTemplate::updateOrCreate(
                    ['role_type' => $roleType, 'feature_id' => $feature->id],
                    [
                        'can_create' => $perms[0],
                        'can_read' => $perms[1],
                        'can_update' => $perms[2],
                        'can_delete' => $perms[3],
                    ]
                );
            }
        }
    }
}
