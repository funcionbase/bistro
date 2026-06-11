<?php

declare(strict_types=1);

/**
 * Fuente única de verdad de los estados de Company. Toda lectura/escritura de
 * `companies.status` (backend, frontend vía Inertia shared props, workflow ops
 * `company-status.yml`) debe consumir esta config en lugar de literales
 * hard-coded.
 *
 * Modelo de estados (post #175):
 *  - pending_activation: default al crear empresa, esperando workflow ops.
 *  - active: operando OK (paga al día o en período de prueba).
 *  - past_due: tiene ≥1 factura vencida y el atraso ≤ 3 meses calendario.
 *  - suspended: atraso > 3 meses, bloqueo comercial.
 *  - rejected: workflow de verificación marcó la empresa como inválida.
 *  - inactive: baja voluntaria/administrativa (no usado por past_due).
 *
 * Estados retirados por #175: `verified` (colapsado en `active`) y `delinquent`
 * (colapsado en `past_due`).
 *
 * Gates de negocio:
 *  - `EnsureCompanyVerified`: permite operar sólo a empresas en
 *    `config('companies.verified')` = ['active','past_due','suspended'].
 *    Bloquea pre-onboarding (`pending_activation`, `rejected`, `inactive`).
 *  - `EnsureCompanyNotBlocked` (#175): bloquea `suspended` con excepciones
 *    explícitas para rutas de facturación y carga de comprobante de pago.
 */
return [

    /**
     * Listado completo de status válidos en BD. Debe coincidir con el CHECK
     * constraint definido en la migración de `companies`.
     *
     * @var list<string>
     */
    'all' => [
        'pending_activation',
        'rejected',
        'active',
        'inactive',
        'past_due',
        'suspended',
    ],

    /**
     * Estados que pasan el gate `EnsureCompanyVerified` (semántica: la empresa
     * ya está onboardeada y se permite que sus requests entren al stack). El
     * sub-gate `EnsureCompanyNotBlocked` decide después si la ruta concreta es
     * accesible cuando está `suspended` (sólo billing + comprobantes).
     *
     * @var list<string>
     */
    'verified' => [
        'active',
        'past_due',
        'suspended',
    ],

    /**
     * Estados de "esperando verificación" — la empresa autenticada puede ver
     * el estado de su solicitud pero no operar.
     *
     * @var list<string>
     */
    'pending' => [
        'pending_activation',
    ],

    /**
     * Estados terminales/bloqueantes (UI muestra "Cuenta en revisión" o equiv.).
     *
     * @var list<string>
     */
    'blocked' => [
        'rejected',
        'inactive',
    ],

    /**
     * Estados de bloqueo comercial por atraso de pago prolongado. Bloquean
     * toda navegación operativa pero dejan acceder a `/billing` y al endpoint
     * de comprobante de pago.
     *
     * @var list<string>
     */
    'fully_blocked' => [
        'suspended',
    ],

    /**
     * Default para nuevas empresas creadas vía enrollment. Definido también
     * como DEFAULT a nivel de BD por la migración del bloque 01.
     */
    'default' => env('DEFAULT_COMPANY_STATUS', 'pending_activation'),

    /**
     * Transiciones permitidas para el workflow operativo `company-status.yml`.
     * El workflow rechaza cualquier par (from, to) que no esté listado aquí.
     * Idempotencia (`from == to`) se trata como no-op en el workflow, sin
     * generar entrada en `audit_logs`.
     *
     * @var array<string, list<string>>
     */
    'allowed_transitions' => [
        'pending_activation' => ['active', 'rejected'],
        'rejected' => ['pending_activation'],
    ],

    /**
     * Etiquetas en español para UI y respuestas API.
     *
     * @var array<string, string>
     */
    'labels' => [
        'pending_activation' => 'Pendiente de verificación',
        'rejected' => 'Rechazada',
        'active' => 'Activa',
        'inactive' => 'Inactiva',
        'past_due' => 'En mora',
        'suspended' => 'Suspendida',
    ],
];
