<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Auditoría del módulo de chats (plan 8-whatsapp.md §7.6).
 *
 * Envuelve a {@see AuditService} para concentrar en un solo archivo las dos
 * reglas que, desperdigadas por los call sites, se rompen sin que nadie lo note:
 *
 * 1. **Deduplicación.** Sin ella la auditoría se convierte en el mayor generador
 *    de escrituras del sistema. Los TTL viven en {@see self::DEDUPE_TTL}, no en
 *    cada controller.
 *
 * 2. **PII.** `data` guarda SOLO identificadores: `chat_id`, `channel_id`,
 *    `company_nit`, longitud del mensaje. **Nunca** `client_phone` ni el cuerpo
 *    del mensaje — eso ya vive en `chats`/`chat_messages`, y duplicarlo en una
 *    tabla inmutable multiplica la exposición y complica cualquier borrado por
 *    habeas data. Las claves prohibidas se filtran acá, no por disciplina del
 *    que llama.
 *
 * El listado de la bandeja NO se audita a propósito: con polling de 30 s serían
 * dos filas por minuto por operador, ruido puro que además tapa la señal real.
 */
class ChatAuditLogger
{
    /**
     * TTL de deduplicación en segundos, por acción. Lo que no está acá se
     * registra siempre (acciones de escritura: son pocas y cada una importa).
     *
     * @var array<string, int>
     */
    private const DEDUPE_TTL = [
        // Abrir una conversación: el operador entra y sale del mismo chat
        // decenas de veces por turno.
        'chat.viewed' => 1800,               // 30 min
        'chat.client.viewed' => 1800,        // 30 min

        // `media_url` se regenera en CADA poll de 30 s (la prefirmada cambia, el
        // browser no la cachea), así que la bandeja dispara un GET por imagen
        // por poll. Sin dedupe, un chat con 10 imágenes abierto 5 minutos son
        // ~100 filas por operador — un orden de magnitud peor que el listado de
        // la bandeja, que el plan excluye justamente por generar 2 filas/min.
        // El dedupe es por (usuario, MENSAJE): conserva la señal que importa
        // —quién accedió a qué media— y corta el volumen de raíz.
        'chat.media.viewed' => 1800,         // 30 min

        // Un rechazo repetido es señal de ataque; 5 min alcanza para verlo sin
        // que un script pueda inflar la tabla a voluntad.
        'chat.access.denied' => 300,         // 5 min
        'whatsapp.channel.qr_viewed' => 300, // 5 min

        // El bot relee el historial en cada turno de conversación.
        'chat.history.read_by_bot' => 900,   // 15 min
    ];

    /**
     * Claves que jamás pueden entrar a `data`, por más que un caller las pase.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'client_phone', 'phone', 'body', 'message', 'text', 'client_name', 'vcard',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Registra la acción salvo que el dedupe la haya visto hace poco.
     *
     * @param  array<string, mixed>  $data
     * @param  ?string  $dedupeKey  Identificador del recurso para el dedupe
     *                              (chat_id, message_id, channel_id). Con TTL
     *                              configurado y sin esto, el dedupe sería por
     *                              usuario y taparía accesos a chats distintos.
     * @return bool `false` si se omitió por dedupe.
     */
    public function log(
        string $action,
        ?User $user = null,
        ?Model $auditable = null,
        array $data = [],
        ?Request $request = null,
        ?string $dedupeKey = null,
    ): bool {
        if (! $this->shouldRecord($action, $user?->id, $dedupeKey)) {
            return false;
        }

        $this->audit->log($action, $user, $auditable, $this->scrub($data), $request);

        return true;
    }

    /**
     * `Cache::add` es atómico: devuelve false si la clave ya existía. Sobre el
     * store `database` (compartido) el dedupe vale para todo el ASG, no por
     * instancia — con el store `file` cada EC2 tendría su propio contador y el
     * ahorro se dividiría por N.
     */
    private function shouldRecord(string $action, ?string $userId, ?string $dedupeKey): bool
    {
        $ttl = self::DEDUPE_TTL[$action] ?? null;

        if ($ttl === null) {
            return true;
        }

        return Cache::add(
            sprintf('audit:%s:%s:%s', $action, $userId ?? 'system', $dedupeKey ?? 'global'),
            true,
            $ttl,
        );
    }

    /**
     * Filtra PII. Se recorre en profundidad porque `before`/`after` de
     * `chat.contact.updated` son arrays anidados.
     *
     * Excepción deliberada: ese `before`/`after` ES el cambio auditado —
     * auditarlo sin los valores no dice nada. Se marca con `_pii_exempt` para
     * que quede explícito que la excepción es intencional y no un descuido.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scrub(array $data): array
    {
        if (($data['_pii_exempt'] ?? false) === true) {
            unset($data['_pii_exempt']);

            return $data;
        }

        foreach ($data as $key => $value) {
            if (in_array($key, self::FORBIDDEN_KEYS, true)) {
                unset($data[$key]);

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }
}
