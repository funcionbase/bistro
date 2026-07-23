<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $features = [
            [
                'slug' => 'orders.read',
                'name' => 'Ver órdenes',
                'description' => 'Permite ver las órdenes de la empresa',
                'group' => 'Órdenes',
            ],
            [
                'slug' => 'orders.create',
                'name' => 'Crear órdenes',
                'description' => 'Permite crear nuevas órdenes',
                'group' => 'Órdenes',
            ],
            [
                'slug' => 'orders.update',
                'name' => 'Actualizar órdenes',
                'description' => 'Permite modificar órdenes existentes',
                'group' => 'Órdenes',
            ],
            [
                'slug' => 'orders.delete',
                'name' => 'Eliminar órdenes',
                'description' => 'Permite eliminar órdenes',
                'group' => 'Órdenes',
            ],
            [
                'slug' => 'users.read',
                'name' => 'Ver usuarios',
                'description' => 'Permite ver los usuarios de la empresa',
                'group' => 'Usuarios',
            ],
            [
                'slug' => 'users.update',
                'name' => 'Editar usuarios',
                'description' => 'Permite editar usuarios de la empresa',
                'group' => 'Usuarios',
            ],
            [
                'slug' => 'roles.create',
                'name' => 'Crear roles',
                'description' => 'Permite crear nuevos roles',
                'group' => 'Usuarios',
            ],
            [
                'slug' => 'roles.read',
                'name' => 'Ver roles',
                'description' => 'Permite ver los roles de la empresa',
                'group' => 'Usuarios',
            ],
            [
                'slug' => 'roles.update',
                'name' => 'Editar roles',
                'description' => 'Permite editar roles de la empresa',
                'group' => 'Usuarios',
            ],
            [
                'slug' => 'roles.delete',
                'name' => 'Eliminar roles',
                'description' => 'Permite eliminar roles de la empresa',
                'group' => 'Usuarios',
            ],
            [
                'slug' => 'reports.read',
                'name' => 'Ver reportes',
                'description' => 'Permite ver reportes de la empresa',
                'group' => 'Reportes',
            ],
            [
                'slug' => 'company.update',
                'name' => 'Editar empresa',
                'description' => 'Permite editar la información de la empresa',
                'group' => 'Empresa',
            ],
            [
                // Perfil fiscal del emisor (DIAN): representante legal, DV, CIIU,
                // responsabilidades fiscales, municipio, contacto/dirección de
                // facturación. Se edita desde /company/settings → "Información".
                // Owner-only por template (admin/operativos = ----); los roles de
                // sistema bypassean por is_system. Separado de company.update para
                // poder restringir la identidad fiscal a roles operativos.
                'slug' => 'company.fiscal_profile',
                'name' => 'Editar perfil fiscal',
                'description' => 'Permite editar el perfil fiscal del emisor (representante legal, CIIU, responsabilidades DIAN, contacto de facturación).',
                'group' => 'Empresa',
            ],
            [
                'slug' => 'chats.read',
                'name' => 'Ver chats',
                'description' => 'Permite ver los chats de la empresa',
                'group' => 'Chats',
            ],
            [
                'slug' => 'chats.update',
                'name' => 'Responder chats',
                'description' => 'Permite enviar mensajes manuales a los clientes',
                'group' => 'Chats',
            ],
            // Supervisión de la bandeja. Deliberadamente NO se otorga a los roles
            // que atienden chats: quien es auditado no administra su auditoría.
            [
                'slug' => 'chats.audit',
                'name' => 'Ver la actividad de una conversación',
                'description' => 'Permite ver quién abrió, respondió y reasignó cada conversación. Pensado para supervisión: no se otorga a quien atiende los chats.',
                'group' => 'Chats',
            ],
            [
                'slug' => 'menu.read',
                'name' => 'Ver menú',
                'description' => 'Permite ver el menú del restaurante',
                'group' => 'Menú',
            ],
            [
                'slug' => 'menu.create',
                'name' => 'Crear categorías y platos',
                'description' => 'Permite crear categorías y platos en el menú',
                'group' => 'Menú',
            ],
            [
                'slug' => 'menu.update',
                'name' => 'Editar menú y disponibilidad',
                'description' => 'Permite editar el menú y cambiar disponibilidad de platos',
                'group' => 'Menú',
            ],
            [
                'slug' => 'menu.delete',
                'name' => 'Eliminar categorías y platos',
                'description' => 'Permite eliminar categorías y platos del menú',
                'group' => 'Menú',
            ],
            [
                'slug' => 'coupons.create',
                'name' => 'Crear cupones',
                'description' => 'Permite crear cupones de descuento',
                'group' => 'Cupones',
            ],
            [
                'slug' => 'coupons.read',
                'name' => 'Ver cupones',
                'description' => 'Permite ver y listar cupones de descuento',
                'group' => 'Cupones',
            ],
            [
                'slug' => 'coupons.update',
                'name' => 'Editar cupones',
                'description' => 'Permite editar y desactivar cupones de descuento',
                'group' => 'Cupones',
            ],
            [
                'slug' => 'coupons.delete',
                'name' => 'Eliminar cupones',
                'description' => 'Permite eliminar cupones de descuento',
                'group' => 'Cupones',
            ],
            [
                'slug' => 'deliveries.read',
                'name' => 'Ver entregas',
                'description' => 'Permite ver y listar entregas de la empresa',
                'group' => 'Entregas',
            ],
            [
                'slug' => 'deliveries.create',
                'name' => 'Asignar repartidores',
                'description' => 'Permite crear y asignar repartidores a órdenes',
                'group' => 'Entregas',
            ],
            [
                'slug' => 'deliveries.update',
                'name' => 'Gestionar entregas',
                'description' => 'Permite completar, reasignar y modificar entregas',
                'group' => 'Entregas',
            ],
            [
                'slug' => 'deliveries.delete',
                'name' => 'Cancelar entregas',
                'description' => 'Permite cancelar entregas',
                'group' => 'Entregas',
            ],
            // El domiciliario se asigna a sí mismo órdenes
            // disponibles de su sede (auto-asignación). Default: rol
            // Domiciliario lo recibe en seeder; otros roles (admin) también
            // por el patrón general de admin → access total.
            [
                'slug' => 'deliveries.self_assign',
                'name' => 'Auto-asignarse entregas',
                'description' => 'Permite al usuario tomar entregas disponibles de su sede sin que un admin las asigne.',
                'group' => 'Entregas',
            ],
            [
                'slug' => 'hours.read',
                'name' => 'Ver horarios',
                'description' => 'Permite ver el horario de operación y las excepciones',
                'group' => 'Horarios',
            ],
            [
                'slug' => 'hours.update',
                'name' => 'Editar horarios',
                'description' => 'Permite editar el horario de operación y gestionar excepciones',
                'group' => 'Horarios',
            ],
            [
                'slug' => 'billing.read',
                'name' => 'Ver facturación',
                'description' => 'Permite ver la suscripción, facturas y estado de facturación de la empresa',
                'group' => 'Facturación',
            ],
            [
                'slug' => 'inventory.read',
                'name' => 'Ver inventario',
                'description' => 'Permite ver insumos, valorización y movimientos del inventario',
                'group' => 'Inventario',
            ],
            [
                'slug' => 'inventory.create',
                'name' => 'Crear insumos y movimientos',
                'description' => 'Permite dar de alta insumos y registrar entradas/mermas',
                'group' => 'Inventario',
            ],
            [
                'slug' => 'inventory.update',
                'name' => 'Editar inventario y ajustar existencias',
                'description' => 'Permite editar metadatos de insumos, restaurar archivados y registrar ajustes manuales',
                'group' => 'Inventario',
            ],
            [
                'slug' => 'inventory.delete',
                'name' => 'Archivar insumos',
                'description' => 'Permite archivar (soft-delete) insumos del catálogo',
                'group' => 'Inventario',
            ],
            [
                'slug' => 'suppliers.read',
                'name' => 'Ver proveedores',
                'description' => 'Permite ver el listado y detalle de proveedores',
                'group' => 'Compras',
            ],
            [
                'slug' => 'suppliers.create',
                'name' => 'Crear proveedores',
                'description' => 'Permite registrar nuevos proveedores',
                'group' => 'Compras',
            ],
            [
                'slug' => 'suppliers.update',
                'name' => 'Editar proveedores',
                'description' => 'Permite editar y restaurar proveedores',
                'group' => 'Compras',
            ],
            [
                'slug' => 'suppliers.delete',
                'name' => 'Archivar proveedores',
                'description' => 'Permite archivar (soft-delete) proveedores',
                'group' => 'Compras',
            ],
            [
                'slug' => 'purchases.read',
                'name' => 'Ver órdenes de compra',
                'description' => 'Permite ver órdenes de compra, sus líneas, adjuntos y notas crédito',
                'group' => 'Compras',
            ],
            [
                'slug' => 'purchases.create',
                'name' => 'Crear órdenes de compra',
                'description' => 'Permite crear borradores de órdenes de compra',
                'group' => 'Compras',
            ],
            [
                'slug' => 'purchases.update',
                'name' => 'Editar órdenes de compra',
                'description' => 'Permite editar borradores, confirmar, cancelar y gestionar adjuntos',
                'group' => 'Compras',
            ],
            [
                'slug' => 'purchases.receive',
                'name' => 'Recibir órdenes de compra',
                'description' => 'Permite marcar como recibida una orden y mover el inventario (entry)',
                'group' => 'Compras',
            ],
            [
                'slug' => 'purchases.pay',
                'name' => 'Pagar órdenes de compra',
                'description' => 'Permite registrar el pago al proveedor (efectivo / tarjeta / transferencia)',
                'group' => 'Compras',
            ],
            [
                'slug' => 'purchases.delete',
                'name' => 'Anular órdenes de compra',
                'description' => 'Permite anular órdenes recibidas con nota crédito y reverso de inventario',
                'group' => 'Compras',
            ],
            [
                'slug' => 'whatsapp.read',
                'name' => 'Ver WhatsApp',
                'description' => 'Permite ver el módulo de configuración de WhatsApp y el estado de conexión',
                'group' => 'WhatsApp',
            ],
            [
                'slug' => 'whatsapp.connect',
                'name' => 'Conectar WhatsApp',
                'description' => 'Permite iniciar Embedded Signup o solicitar Number-as-a-Service',
                'group' => 'WhatsApp',
            ],
            [
                'slug' => 'whatsapp.update',
                'name' => 'Editar WhatsApp',
                'description' => 'Permite editar Display Name, suscribir webhook y sincronizar metadata desde Meta',
                'group' => 'WhatsApp',
            ],
            [
                'slug' => 'whatsapp.swap_phone',
                'name' => 'Cambiar número de WhatsApp',
                'description' => 'Permite cambiar el número conectado (libera el actual + relanza Embedded Signup). Solo owner.',
                'group' => 'WhatsApp',
                'is_owner_only' => true,
            ],
            [
                'slug' => 'whatsapp.disconnect',
                'name' => 'Desconectar WhatsApp',
                'description' => 'Permite desconectar la cuenta de WhatsApp (libera el número en Meta + soft-delete). Solo owner.',
                'group' => 'WhatsApp',
                'is_owner_only' => true,
            ],
            // Canales por sede (multi-canal). Se compone con whatsapp.connect +
            // acceso a la sede, igual que chats.reassign_branch: nadie lo recibe
            // automáticamente, se delega a un jefe de sede desde el editor.
            [
                'slug' => 'whatsapp.manage_branch_channels',
                'name' => 'Gestionar el WhatsApp de una sede',
                'description' => 'Permite conectar y desconectar el número de WhatsApp de una sede a la que el usuario tenga acceso. Se compone con "Conectar WhatsApp".',
                'group' => 'WhatsApp',
            ],
            // Sedes (multi-sede #117).
            [
                'slug' => 'branches.manage',
                'name' => 'Gestionar sedes',
                'description' => 'Crear, editar y archivar sedes de la empresa.',
                'group' => 'Sedes',
            ],
            // Aislamiento por sede (#192). Permisos sensibles que vienen
            // owner-only por default y son asignables a otros roles desde el
            // editor de permisos. No CRUD: la lógica del backend lee
            // jwt.permissions buscando el slug exacto.
            [
                'slug' => 'chats.reassign_branch',
                'name' => 'Reasignar chats a otra sede',
                'description' => 'Permite mover un chat de WhatsApp hacia otra sede a la que el operador tenga acceso.',
                'group' => 'Chats',
            ],
            [
                'slug' => 'cash_register.bypass_switch_lock',
                'name' => 'Cambiar de sede con caja abierta',
                'description' => 'Permite cambiar de sede activa aunque exista una sesión de caja abierta. Se audita.',
                'group' => 'Caja',
            ],
            [
                'slug' => 'cash_register.manage',
                'name' => 'Configurar cajas de la sede',
                'description' => 'Permite crear, renombrar y archivar las cajas registradoras de la sede (multi-caja). Sensible de sede: se asigna manual.',
                'group' => 'Caja',
            ],
            [
                'slug' => 'cash_register.operate_others',
                'name' => 'Operar caja de otro cajero',
                'description' => 'Permite cerrar la caja que abrió otro cajero (toma de turno / supervisión). Se audita. Sensible de sede: se asigna manual.',
                'group' => 'Caja',
            ],
            [
                'slug' => 'inventory.transfer_cross_branch',
                'name' => 'Transferir inventario entre sedes',
                'description' => 'Permite registrar transferencias de inventario con doble asiento entre sedes distintas. (Endpoint dedicado, fuera de scope de #192 — habilitado para asignación previa.)',
                'group' => 'Inventario',
            ],
            [
                'slug' => 'branches.assign_users',
                'name' => 'Asignar usuarios a sedes',
                'description' => 'Otorgar y revocar acceso de usuarios a sedes específicas.',
                'group' => 'Sedes',
            ],
            [
                'slug' => 'branches.copy_menu',
                'name' => 'Copiar menú entre sedes',
                'description' => 'Duplicar el menú de una sede a otra como punto de partida (luego se vuelven independientes).',
                'group' => 'Sedes',
            ],
            [
                'slug' => 'branches.view_all',
                'name' => 'Ver todas las sedes',
                'description' => 'Consultar el listado completo de sedes de la empresa.',
                'group' => 'Sedes',
            ],
            [
                'slug' => 'metrics.view_all_branches',
                'name' => 'Reportes consolidados',
                'description' => 'Ver métricas y reportes de todas las sedes simultáneamente.',
                'group' => 'Reportes',
            ],
            // Bodegas (multi-bodega #120).
            [
                'slug' => 'warehouses.manage',
                'name' => 'Gestionar bodegas',
                'description' => 'Crear, editar y archivar bodegas de la empresa (cocina, barra, congelador, etc.).',
                'group' => 'Inventario',
            ],
            // Asignación de bodegas a sedes (#costeo-multibodega). Una bodega
            // es company-scoped y puede servir a N sedes; este permiso controla
            // quién asigna/desasigna y marca la bodega default de cada sede.
            // Config cross-sede → owner + admin por default.
            [
                'slug' => 'warehouses.assign_branches',
                'name' => 'Asignar bodegas a sedes',
                'description' => 'Asignar y desasignar bodegas a sedes y marcar la bodega default de cada sede.',
                'group' => 'Inventario',
            ],
            // CRM básico de clientes (#123). Vista cross-sede (un teléfono =
            // un cliente para toda la empresa).
            //
            // Históricamente los clientes se "creaban" implícitamente al
            // registrar la primera orden. `clients.create` agrega el flujo
            // manual desde el CRM (botón "Nuevo cliente"), útil para
            // pre-cargar contactos antes de la primera venta o capturar
            // walk-ins por teléfono.
            [
                'slug' => 'clients.read',
                'name' => 'Ver clientes',
                'description' => 'Permite ver el CRM de clientes, su historial de pedidos y notas privadas.',
                'group' => 'Clientes',
            ],
            [
                'slug' => 'clients.create',
                'name' => 'Registrar clientes',
                'description' => 'Permite registrar manualmente un cliente nuevo (nombre + teléfono).',
                'group' => 'Clientes',
            ],
            [
                'slug' => 'clients.update',
                'name' => 'Editar notas y etiquetas',
                'description' => 'Permite agregar notas privadas y etiquetas a clientes.',
                'group' => 'Clientes',
            ],
            [
                'slug' => 'clients.delete',
                'name' => 'Eliminar notas y etiquetas',
                'description' => 'Permite eliminar notas privadas y etiquetas de clientes.',
                'group' => 'Clientes',
            ],
            // Fidelización con puntos (#122). Saldo cross-sede por (company_nit,
            // client_phone). loyalty.read viene por defecto en
            // DEFAULT_EMPLOYEE_PERMISSIONS. loyalty.update controla ajustes
            // manuales de balance y canjes desde el staff.
            [
                'slug' => 'loyalty.read',
                'name' => 'Ver fidelización',
                'description' => 'Permite ver cuentas de puntos, saldos, tiers y movimientos de fidelización.',
                'group' => 'Fidelización',
            ],
            [
                'slug' => 'loyalty.update',
                'name' => 'Ajustar puntos y canjear',
                'description' => 'Permite hacer ajustes manuales al saldo de puntos y emitir canjes a nombre del cliente.',
                'group' => 'Fidelización',
            ],
            // Colaboradores y planificador de turnos (#182).
            [
                'slug' => 'employees.read',
                'name' => 'Ver colaboradores',
                'description' => 'Permite ver el listado de colaboradores, sus datos HHRR y agenda.',
                'group' => 'Colaboradores',
            ],
            [
                'slug' => 'employees.create',
                'name' => 'Crear colaboradores',
                'description' => 'Permite registrar nuevos colaboradores con perfil HHRR completo.',
                'group' => 'Colaboradores',
            ],
            [
                'slug' => 'employees.update',
                'name' => 'Editar colaboradores',
                'description' => 'Permite editar perfil, datos de pago y vinculación de colaboradores.',
                'group' => 'Colaboradores',
            ],
            [
                'slug' => 'employees.delete',
                'name' => 'Archivar colaboradores',
                'description' => 'Permite archivar (soft-delete) colaboradores conservando historial DIAN.',
                'group' => 'Colaboradores',
            ],
            [
                'slug' => 'employees.view_salary',
                'name' => 'Ver salario sin máscara',
                'description' => 'Permite revelar el pay_rate de colaboradores ajenos. Auditado por consulta.',
                'group' => 'Colaboradores',
            ],
            [
                'slug' => 'shifts.read',
                'name' => 'Ver planificador de turnos',
                'description' => 'Permite ver la vista semanal y mensual del planificador de turnos.',
                'group' => 'Planificador',
            ],
            [
                'slug' => 'shifts.manage',
                'name' => 'Gestionar turnos',
                'description' => 'Permite crear, editar y cancelar turnos en el planificador.',
                'group' => 'Planificador',
            ],
            [
                'slug' => 'shifts.suggest',
                'name' => 'Sugerir asignación de turnos',
                'description' => 'Permite generar borradores automáticos de asignación equitativa.',
                'group' => 'Planificador',
            ],
            [
                'slug' => 'workforce.reports',
                'name' => 'Informes de colaboradores',
                'description' => 'Permite ver y exportar reportes consolidados de horas y costo estimado.',
                'group' => 'Reportes',
            ],
            [
                'slug' => 'workforce.settings',
                'name' => 'Configurar jornada laboral',
                'description' => 'Permite configurar máximo de horas semanales, mínimo de días libres y modo de aviso.',
                'group' => 'Colaboradores',
            ],
            // KDS (Kitchen Display System): operación cocinero.
            // Patrón CRUD estándar — los cocineros/manager/supervisor
            // reciben can_read (ver tickets) y can_update (operar status).
            // can_create / can_delete no se usan hoy en el flujo cocina
            // pero se siembran para consistencia y futuro uso.
            [
                'slug' => 'kds.read',
                'name' => 'Ver KDS',
                'description' => 'Permite ver la pantalla de cocina con tickets activos y estaciones.',
                'group' => 'Cocina (KDS)',
            ],
            [
                'slug' => 'kds.create',
                'name' => 'Crear en KDS',
                'description' => 'Reservado para futuro uso (push manual de items, etc.).',
                'group' => 'Cocina (KDS)',
            ],
            [
                'slug' => 'kds.update',
                'name' => 'Operar KDS',
                'description' => 'Permite marcar items como en cocina, listos o servidos.',
                'group' => 'Cocina (KDS)',
            ],
            [
                'slug' => 'kds.delete',
                'name' => 'Eliminar en KDS',
                'description' => 'Reservado para futuro uso (cancelaciones manuales, etc.).',
                'group' => 'Cocina (KDS)',
            ],
            // Gestión de estaciones + device-tokens. Sensible de
            // sede: default `[false,false,false,false]` para admin
            // (mismo patrón que cash_register.bypass_switch_lock,
            // inventory.transfer_cross_branch, chats.reassign_branch).
            [
                'slug' => 'kds_stations.read',
                'name' => 'Ver gestión de estaciones KDS',
                'description' => 'Permite acceder a /company/kds (CRUD de estaciones y device-tokens).',
                'group' => 'Cocina (KDS)',
            ],
            [
                'slug' => 'kds_stations.create',
                'name' => 'Crear estaciones / tokens KDS',
                'description' => 'Permite crear estaciones de cocina y generar device-tokens.',
                'group' => 'Cocina (KDS)',
            ],
            [
                'slug' => 'kds_stations.update',
                'name' => 'Editar estaciones KDS',
                'description' => 'Permite renombrar estaciones y ajustar SLA / color / is_default.',
                'group' => 'Cocina (KDS)',
            ],
            [
                'slug' => 'kds_stations.delete',
                'name' => 'Archivar estaciones / revocar tokens KDS',
                'description' => 'Permite archivar estaciones y revocar device-tokens activos.',
                'group' => 'Cocina (KDS)',
            ],
            // Notificaciones Push PWA. Patrón CRUD canónico; default
            // [true, true, true, true] para TODOS los roles porque cada user
            // gestiona SUS propias suscripciones (self-service). El sistema
            // decide a quién enviar push según permisos operativos
            // (orders.update, reports.read), no según notifications.*. Estos
            // slugs gobiernan únicamente el acceso del user a
            // /settings/notifications y los endpoints REST de gestión propia.
            [
                'slug' => 'notifications.read',
                'name' => 'Ver mis suscripciones push',
                'description' => 'Permite ver las propias suscripciones de notificaciones push y las preferencias por tipo.',
                'group' => 'Notificaciones',
            ],
            [
                'slug' => 'notifications.create',
                'name' => 'Suscribir dispositivo a push',
                'description' => 'Permite registrar el dispositivo actual para recibir notificaciones push.',
                'group' => 'Notificaciones',
            ],
            [
                'slug' => 'notifications.update',
                'name' => 'Editar preferencias de push',
                'description' => 'Permite activar o desactivar tipos de notificación (pendientes, inventario) por dispositivo.',
                'group' => 'Notificaciones',
            ],
            [
                'slug' => 'notifications.delete',
                'name' => 'Quitar suscripción de dispositivo',
                'description' => 'Permite revocar la suscripción de un dispositivo propio.',
                'group' => 'Notificaciones',
            ],
            // Facturación Electrónica DIAN.
            // Configuración global de empresa (owner-only por default;
            // dian.config.read es asignable a admin para consulta de tokens
            // enmascarados). dian.config.write y dian.default_recipient.write
            // viven solo en el owner — escribir credenciales del proveedor
            // o cambiar adquirente por defecto es acción sensible.
            [
                'slug' => 'dian.config.read',
                'name' => 'Ver configuración DIAN',
                'description' => 'Permite ver el perfil fiscal, resoluciones y configuración del proveedor DIAN (tokens enmascarados).',
                'group' => 'Facturación DIAN',
            ],
            [
                'slug' => 'dian.config.write',
                'name' => 'Editar configuración DIAN',
                'description' => 'Permite editar perfil fiscal, registrar resoluciones y rotar tokens del proveedor DIAN.',
                'group' => 'Facturación DIAN',
            ],
            [
                'slug' => 'dian.default_recipient.write',
                'name' => 'Configurar cliente DIAN por defecto',
                'description' => 'Permite definir el adquirente por defecto de la empresa para emisión automática.',
                'group' => 'Facturación DIAN',
            ],
            [
                'slug' => 'dian.documents.read',
                'name' => 'Ver documentos DIAN',
                'description' => 'Permite ver y filtrar el listado de documentos electrónicos emitidos.',
                'group' => 'Facturación DIAN',
            ],
            [
                'slug' => 'dian.documents.emit',
                'name' => 'Emitir documentos DIAN',
                'description' => 'Permite emitir DEE POS / FEV desde una orden completada.',
                'group' => 'Facturación DIAN',
            ],
            [
                'slug' => 'dian.documents.credit_note',
                'name' => 'Emitir nota crédito DIAN',
                'description' => 'Permite emitir notas crédito sobre documentos aceptados.',
                'group' => 'Facturación DIAN',
            ],
            [
                'slug' => 'dian.documents.retry',
                'name' => 'Reintentar documentos DIAN',
                'description' => 'Permite reintentar el envío de documentos en estado error o rechazado.',
                'group' => 'Facturación DIAN',
            ],
            [
                'slug' => 'dian.recipients.read',
                'name' => 'Ver perfiles DIAN de clientes',
                'description' => 'Permite consultar los datos fiscales registrados en contactos para emisión.',
                'group' => 'Facturación DIAN',
            ],
            [
                'slug' => 'dian.recipients.write',
                'name' => 'Editar perfiles DIAN de clientes',
                'description' => 'Permite completar y editar el perfil fiscal de un contacto.',
                'group' => 'Facturación DIAN',
            ],
            [
                'slug' => 'dian.print',
                'name' => 'Imprimir tirilla DIAN',
                'description' => 'Permite imprimir o reimprimir la tirilla térmica DIAN de un documento.',
                'group' => 'Facturación DIAN',
            ],
        ];

        foreach ($features as $feature) {
            $feature['is_owner_only'] = $feature['is_owner_only'] ?? false;

            Feature::updateOrCreate([
                'slug' => $feature['slug'],
            ], $feature);
        }
    }
}
