<?php

declare(strict_types=1);

/**
 * Fuente única de verdad para el enum `employees.vinculation_status`.
 *
 * Cualquier código backend (validaciones `Rule::in`, scopes, policies) y
 * cualquier consumidor frontend (vía Inertia shared prop `employeeStatuses`)
 * debe leer de aquí en lugar de literales hard-coded.
 *
 * Distinciones:
 *  - `vinculation_statuses`: set canónico aceptado por la columna BD
 *    (enum CHECK constraint en PostgreSQL).
 *  - `labels`: textos es-CO para UI/badges/PDFs.
 *  - `badges`: variante visual del Badge primitive (`safe`/`warning`/`critical`).
 *
 * Reglas operativas detalladas: ver `application/constants/EMPLOYEE_STATUSES.md`.
 * Reglas de transición y policy: `EmployeeVinculationPolicy` + controller
 * `EmployeeController::changeVinculationState`.
 */
return [

    // Lista cerrada del enum BD.
    'vinculation_statuses' => ['active', 'inactive', 'vacation', 'sick_leave', 'compensatory'],

    // Estados que requieren rango de fechas (vinculation_valid_from + vinculation_valid_until).
    'absence_statuses' => ['vacation', 'sick_leave', 'compensatory'],

    'labels' => [
        'active' => 'Activo',
        'inactive' => 'Inactivo',
        'vacation' => 'Vacaciones',
        'sick_leave' => 'Incapacidad',
        'compensatory' => 'Compensatorio',
    ],

    'badges' => [
        'active' => 'safe',
        'inactive' => 'critical',
        'vacation' => 'warning',
        'sick_leave' => 'warning',
        'compensatory' => 'warning',
    ],
];
