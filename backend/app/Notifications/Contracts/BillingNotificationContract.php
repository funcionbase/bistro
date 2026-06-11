<?php

namespace App\Notifications\Contracts;

/**
 * #257 — Contrato de notificaciones billing que se despachan via
 * NotificationDispatchTracker.
 *
 * Las clases que implementan este contrato deben proveer:
 *
 *  - idempotencyKey(): identifica univocamente UNA instancia logica del
 *    evento. Estable a traves de re-fires del job, reintentos de cola y
 *    ejecuciones manuales. Ejemplos:
 *
 *      'company:{nit}:activated'
 *      'invoice:{id}:generated'
 *      'invoice:{id}:overdue'
 *      'company:{nit}:past_due'
 *      'company:{nit}:suspended'
 *      'company:{nit}:reactivated'
 *      'company:{nit}:blocking_soon:{YYYY-MM-DD}' (1 por dia)
 *
 *  - dispatchMetadata(): array contextual a guardar en
 *    notification_dispatches.metadata. Ej. invoice_id, subscription_id,
 *    period_from. Sirve para auditoria y para reconstruir el evento sin
 *    rejoin a la entidad origen si esta se borra.
 *
 *  - companyNit(): NIT de la empresa receptora, para denormalizar la
 *    fila en notification_dispatches.company_nit (permite queries
 *    "que recibio la empresa X").
 */
interface BillingNotificationContract
{
    public function idempotencyKey(): string;

    /** @return array<string, mixed> */
    public function dispatchMetadata(): array;

    public function companyNit(): string;
}
