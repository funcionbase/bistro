<?php

declare(strict_types=1);

namespace App\Services\Dian\Exceptions;

use App\Models\Contact;
use RuntimeException;

/**
 * Se lanza cuando el lookup por teléfono encuentra un `Contact` cuyo perfil
 * DIAN está incompleto (`dian_profile_completed_at IS NULL` o falta algún
 * mínimo).
 *
 * `DianDispatchService` la captura y marca el `electronic_documents.status`
 * como `needs_recipient_data` para que la UI abra el modal "Faltan datos del
 * cliente para DIAN" y el cajero complete los campos faltantes.
 */
class NeedsRecipientDataException extends RuntimeException
{
    public function __construct(public readonly Contact $contact)
    {
        parent::__construct(sprintf(
            'Contact id=%s (phone=%s) no tiene perfil DIAN completo. UI debe pedir los datos al cajero.',
            (string) $contact->getKey(),
            (string) $contact->phone,
        ));
    }
}
