<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Categorías de egresos de caja
    |--------------------------------------------------------------------------
    |
    | Lista cerrada de categorías permitidas para `cash_register_expenses.category`.
    | Cualquier nuevo tipo de egreso debe agregarse aquí — nunca hardcodear
    | en controllers/services/frontend.
    */

    'expense_categories' => [
        'domiciliario_pago' => 'Pago a domiciliario',
        'proveedor' => 'Proveedor',
        'imprevisto' => 'Imprevisto',
        'propina_distribuida' => 'Propina distribuida',
        'otro' => 'Otro',
    ],

    /*
    |--------------------------------------------------------------------------
    | Métodos de pago para egresos
    |--------------------------------------------------------------------------
    |
    | Espejo de `config('payments.methods')`. NO modificar aquí —
    | actualizar `config/payments.php` y este array reflejará el cambio.
    | Se mantiene la clave para retro-compatibilidad con `cash_register.*`.
    */
    'expense_payment_methods' => ['cash', 'card', 'transfer'],

    /*
    |--------------------------------------------------------------------------
    | Categorías de entradas de efectivo (ingresos NO por venta)
    |--------------------------------------------------------------------------
    |
    | Lista cerrada para `cash_register_incomes.category`. Cubren inyecciones de
    | dinero a la caja que no vienen de un cobro: aportes de socios, préstamos,
    | ajustes positivos de arqueo, otros. Nunca hardcodear en controllers/front.
    */
    'income_categories' => [
        'aporte_socio' => 'Aporte de socio',
        'prestamo' => 'Préstamo / inyección',
        'ajuste_positivo' => 'Ajuste positivo',
        'otro' => 'Otro',
    ],

    /*
    |--------------------------------------------------------------------------
    | Métodos de pago para entradas
    |--------------------------------------------------------------------------
    |
    | Espejo de `expense_payment_methods`. Solo `cash` afecta el efectivo
    | esperado en el arqueo; card/transfer se registran para trazabilidad.
    */
    'income_payment_methods' => ['cash', 'card', 'transfer'],
];
