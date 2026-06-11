<?php

namespace App\Listeners;

use App\Services\EmailDeliveryService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * Aborta el envío si algún destinatario está en la suppression list.
 *
 * Listener síncrono enganchado al evento {@see MessageSending}: devolver
 * `false` desde el handler impide que Symfony Mailer entregue el mensaje
 * al transport (SES). El evento se dispara después del `building` y antes
 * del `sent`, garantizando que la decisión ocurra justo antes del envío.
 *
 * Política:
 *  - Si CUALQUIER destinatario (`to`, `cc`, `bcc`) está suprimido, se
 *    aborta el envío COMPLETO. No se intenta enviar a un subconjunto:
 *    un correo con bcc a usuario suprimido seguiría exponiendo el patrón
 *    de envío, y operativamente es más simple bloquear todo.
 *  - Loguea cada bloqueo en canal `single` con el email afectado y
 *    el subject, para diagnóstico sin ruido en producción.
 *  - NO levanta excepción — el caller no necesita saber. La suppression
 *    es una decisión silenciosa por diseño (evitar que código de billing
 *    intente reaccionar a un bounce previo de manera distinta).
 *
 * Registrado en `bootstrap/app.php` via `Event::listen()` o auto-discovery
 * de `EventServiceProvider`.
 */
class AbortIfSuppressed
{
    public function __construct(private readonly EmailDeliveryService $deliveryService) {}

    public function handle(MessageSending $event): bool
    {
        $message = $event->message;

        // Symfony\Component\Mime\Email expone los recipients via getTo/Cc/Bcc.
        // Cada uno devuelve un array de Symfony\Component\Mime\Address.
        $allRecipients = array_merge(
            $message->getTo(),
            $message->getCc(),
            $message->getBcc(),
        );

        foreach ($allRecipients as $address) {
            if (! $address instanceof Address) {
                continue;
            }

            if ($this->deliveryService->isSuppressed($address->getAddress())) {
                Log::channel('single')->warning('email.send.aborted.suppressed', [
                    'recipient' => $address->getAddress(),
                    'subject' => $message->getSubject(),
                    'from' => $this->extractFrom($message),
                ]);

                // Retornar false aborta el envío y dispara MessageSending::halt().
                return false;
            }
        }

        return true;
    }

    private function extractFrom(\Symfony\Component\Mime\Email $message): ?string
    {
        $from = $message->getFrom();

        return isset($from[0]) ? $from[0]->getAddress() : null;
    }
}
