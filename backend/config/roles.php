<?php

return [
    'system_roles' => array_map('trim', explode(',', env('SYSTEM_ROLES', 'owner,admin,employee'))),

    // Role types operativos que se siembran automáticamente al crear una
    // empresa (auto-seeding). is_system=false (renombrables /
    // eliminables por el owner). Distinto de `system_roles` que son los 3
    // institucionales con bypass.
    //
    // Override via env (ROLES_BOOTSTRAP_TEMPLATES=) permite QA crear
    // empresas "vacías" para reproducir bugs sin estos roles.
    'bootstrap_templates' => array_filter(array_map(
        'trim',
        explode(',', env('ROLES_BOOTSTRAP_TEMPLATES', 'waiter,cook,cashier,courier,manager,accountant,marketing,inventory_manager,supervisor')),
    )),

    'default_employee_permissions' => array_map('trim', explode(',', env('DEFAULT_EMPLOYEE_PERMISSIONS', 'orders.read,chats.read,clients.read'))),

    'role_names' => [
        'owner' => 'Propietario',
        'admin' => 'Administrador',
        'employee' => 'Empleado',
        // Roles operativos del flujo de mesa con QR (Fase 7).
        // No son `system_roles` puros: se crean opcionalmente por empresa
        // vía `php artisan roles:sync-templates`. Heredan templates de
        // PermissionTemplate con role_type correspondiente.
        'waiter' => 'Mesero',
        'cook' => 'Cocinero',
        'cashier' => 'Cajero',
        // Domiciliario: rol operativo courier-only. is_system=false,
        // renombrable. Sólo ve/toma sus entregas → activa el "courier mode".
        'courier' => 'Domiciliario',
        // Roles operativos administrativos (Fase 4). Mismo modelo que los
        // operativos de mesa: is_system=false, asignables/renombrables, sembrables
        // vía `php artisan roles:sync-templates`.
        'manager' => 'Gerente',
        'accountant' => 'Contador',
        'marketing' => 'Marketing',
        'inventory_manager' => 'Bodeguero',
        'supervisor' => 'Supervisor',
    ],

    // Sugerencias de color para los roles operativos al crearlos vía
    // `roles:sync-templates`. El owner puede editarlos después desde
    // /identities/roles.
    'role_colors' => [
        'owner' => '#0F172A',
        'admin' => '#7C3AED',
        'employee' => '#94A3B8',
        'waiter' => '#0EA5E9',
        'cook' => '#F97316',
        'cashier' => '#16A34A',
        'courier' => '#0891B2',           // cyan-600 — "en la calle", distinto de waiter (sky).
        'manager' => '#14B8A6',          // teal-500 — diferenciable de waiter (sky).
        'accountant' => '#475569',        // slate-600 — sobrio, distinto de employee slate-400.
        'marketing' => '#EC4899',         // pink-500 — brand-ish.
        'inventory_manager' => '#A16207', // amber-700 — "bodega".
        'supervisor' => '#6366F1',        // indigo-500 — distinto de admin violet.
    ],
];
