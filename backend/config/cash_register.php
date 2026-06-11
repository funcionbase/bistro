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
    | Espejo de `config('payments.methods')` (#203). NO modificar aquí —
    | actualizar `config/payments.php` y este array reflejará el cambio.
    | Se mantiene la clave para retro-compatibilidad con `cash_register.*`.
    */
    'expense_payment_methods' => ['cash', 'card', 'transfer'],
];
