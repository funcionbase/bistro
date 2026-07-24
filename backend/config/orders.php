<?php

declare(strict_types=1);

/**
 * Fuente única de verdad de los estados de Order. Cualquier código backend o
 * frontend (vía Inertia shared props) debe consumir esta config en lugar de
 * literales hard-coded.
 *
 * Modelo plano sin sub-tipos:
 *  - Operacionales: pending → in_kitchen → ready → in_transit
 *  - Terminales éxito: completed
 *  - Terminales falla: failed (entrega fallida), cancelled (sin pago), refunded
 *    (con devolución), abandoned (carrito nunca confirmado)
 */
return [

    // Listado completo de status válidos en BD.
    //
    // pending_approval (estado nuevo introducido por #191): órdenes de mesa con QR
    // que aún no han sido aprobadas por el mesero. NO entran en `operational`,
    // `kanban`, ni `revenue`. Solo aparecen en pantallas dedicadas (mesero).
    'all' => [
        'pending_approval',
        'pending',
        'in_kitchen',
        'ready',
        'in_transit',
        'completed',
        'failed',
        'cancelled',
        'refunded',
        'abandoned',
    ],

    // Estados activos en el flujo operativo (siguen avanzando hacia terminal).
    'operational' => [
        'pending',
        'in_kitchen',
        'ready',
        'in_transit',
    ],

    // Estados terminales de éxito (entrega/cobro confirmado).
    'terminal_success' => [
        'completed',
    ],

    // Estados terminales de falla.
    'terminal_failure' => [
        'failed',
        'cancelled',
        'refunded',
        'abandoned',
    ],

    // Estados que aparecen en el kanban de órdenes activas.
    'kanban' => [
        'pending',
        'in_kitchen',
        'ready',
        'in_transit',
        'completed',
    ],

    // Rank ordinal de cada columna del kanban. Las órdenes solo pueden avanzar
    // (rank destino > rank actual). Volver a un estado anterior está prohibido —
    // si hubo error, se cancela la orden y se crea una nueva (trazabilidad DIAN).
    // Mismo rank = no-op silencioso. Estados terminales (terminal_failure)
    // bloquean cualquier transición posterior.
    'kanban_rank' => [
        'pending' => 1,
        'in_kitchen' => 2,
        'ready' => 3,
        'in_transit' => 4,
        'completed' => 5,
    ],

    // Estados que cuentan como ingreso confirmado en KPIs e informes.
    'revenue' => [
        'completed',
    ],

    // Etiquetas en español para UI/PDFs.
    'labels' => [
        'pending_approval' => 'Pendiente aprobación',
        'pending' => 'Pendiente',
        'in_kitchen' => 'En cocina',
        'ready' => 'Para entrega',
        'in_transit' => 'En tránsito',
        'completed' => 'Completado',
        'failed' => 'Entrega fallida',
        'cancelled' => 'Cancelado',
        'refunded' => 'Devolución',
        'abandoned' => 'Abandonado',
    ],

    // Clases CSS de badge (Tailwind 100/700-800) usadas en frontend y PDF.
    'badges' => [
        'pending_approval' => 'bg-slate-100 text-slate-700',
        'pending' => 'bg-yellow-100 text-yellow-800',
        'in_kitchen' => 'bg-orange-100 text-orange-800',
        'ready' => 'bg-blue-100 text-blue-800',
        'in_transit' => 'bg-purple-100 text-purple-800',
        'completed' => 'bg-green-100 text-green-800',
        'failed' => 'bg-rose-100 text-rose-700',
        'cancelled' => 'bg-red-100 text-red-700',
        'refunded' => 'bg-pink-100 text-pink-700',
        'abandoned' => 'bg-amber-100 text-amber-700',
    ],

    // Timezone canónico para filtros operativos ("del día", cierre de caja).
    // Todas las empresas operan en Colombia, por lo que el corte de día se hace
    // contra `America/Bogota` (UTC-5 sin DST). NO usar `now()->toDateString()`
    // sin tz explícita en filtros financieros.
    'timezone' => 'America/Bogota',

    // Corte de abandono para pedidos públicos (QR de sede) que nunca fueron
    // aprobados: pasado este umbral, el cron `orders:mark-abandoned` los pasa
    // a `abandoned` (métrica de carritos perdidos) y cancela sus items.
    'abandon_after_hours' => 24,

    // Mapeo de status → categoría (para lógica de UI o policies).
    //
    // `pre_operational` es categoría nueva (#191) para órdenes de mesa con QR
    // que aún no fueron aprobadas por el mesero. No son ingreso ni operación
    // confirmada; viven en una pantalla aparte (mesero) hasta que se aprueban.
    'category' => [
        'pending_approval' => 'pre_operational',
        'pending' => 'operational',
        'in_kitchen' => 'operational',
        'ready' => 'operational',
        'in_transit' => 'operational',
        'completed' => 'terminal_success',
        'failed' => 'terminal_failure',
        'cancelled' => 'terminal_failure',
        'refunded' => 'terminal_failure',
        'abandoned' => 'terminal_failure',
    ],

    // Estados independientes de cada `order_item` (#191). Una orden de mesa
    // tiene varios items con ciclo de vida propio: el cliente los agrega
    // (pending_approval), el mesero los aprueba (approved), cocina los
    // produce (in_kitchen → ready), el mesero los entrega (served), y
    // pueden cancelarse en cualquier momento previo a `served` con razón
    // categorizada (customer/waiter/waiter_approved/kitchen/system/refunded).
    'item_statuses' => [
        'all' => [
            'pending_approval',
            'approved',
            'in_kitchen',
            'ready',
            'served',
            'cancelled',
        ],
        // Items que aún consumen producción (planeada o en curso).
        'operational' => [
            'approved',
            'in_kitchen',
            'ready',
        ],
        // Items consumidos por el comensal — base de cálculo de cuenta a cobrar.
        'consumable' => [
            'approved',
            'in_kitchen',
            'ready',
            'served',
        ],
        // Items que NO entran en orders.total (cancelados se excluyen del invariante).
        'excluded_from_total' => [
            'cancelled',
        ],
        // Rank ordinal del ciclo del item. Forward-only: solo se puede avanzar.
        'kanban_rank' => [
            'pending_approval' => 1,
            'approved' => 2,
            'in_kitchen' => 3,
            'ready' => 4,
            'served' => 5,
        ],
        'labels' => [
            'pending_approval' => 'Por aprobar',
            'approved' => 'Aprobado',
            'in_kitchen' => 'En cocina',
            'ready' => 'Listo',
            'served' => 'Entregado',
            'cancelled' => 'Cancelado',
        ],
        'badges' => [
            'pending_approval' => 'bg-slate-100 text-slate-700',
            'approved' => 'bg-yellow-100 text-yellow-800',
            'in_kitchen' => 'bg-orange-100 text-orange-800',
            'ready' => 'bg-blue-100 text-blue-800',
            'served' => 'bg-green-100 text-green-800',
            'cancelled' => 'bg-red-100 text-red-700',
        ],
        'cancellation_reasons' => [
            'customer' => 'Cancelado por el cliente',
            'waiter' => 'Rechazado por el mesero',
            'waiter_approved' => 'Aprobación de mesero a solicitud del cliente',
            'kitchen' => 'Cancelado por cocina',
            'system' => 'Cancelación automática',
            'refunded' => 'Devuelto tras pago',
        ],
    ],
];
