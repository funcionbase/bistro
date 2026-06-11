<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderItemSubmittedForApproval;
use App\Jobs\SendPendingApprovalPushJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Listener queued del evento `OrderItemSubmittedForApproval` (#149).
 *
 * Convierte el evento síncrono en un job de cola para que el flujo HTTP
 * del comensal (TableOrderService::addItem) no pague la latencia de
 * resolver suscripciones + cifrar payloads + hitear endpoints externos.
 *
 * El job destino (`SendPendingApprovalPushJob`) hace el trabajo real.
 */
class NotifyPendingApprovalListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderItemSubmittedForApproval $event): void
    {
        SendPendingApprovalPushJob::dispatch($event->item->id);
    }
}
