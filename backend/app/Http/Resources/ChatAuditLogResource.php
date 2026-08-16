<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una fila de la pestaña "Actividad" de una conversación (plan §7.6).
 *
 * **`data` NO se expone.** Contiene identificadores internos (ids de canal, de
 * sede, `attempted_company_nit`) que no le dicen nada al operador y que amplían
 * la superficie sin necesidad. La pestaña responde tres preguntas —quién, qué y
 * cuándo— y nada más.
 *
 * Tampoco se exponen `ip_address` ni `user_agent`: son datos de vigilancia sobre
 * los propios empleados, y esta pestaña la ve el dueño del restaurante, no un
 * equipo de seguridad. Quedan en la tabla para una investigación puntual.
 *
 * @mixin AuditLog
 */
class ChatAuditLogResource extends JsonResource
{
    /**
     * Etiquetas legibles. Un slug sin traducir ("chat.bot.toggled") es ruido
     * para quien atiende un restaurante.
     *
     * `chat.reassigned` es el nombre REAL que emite el código — no
     * `chat.reassign_branch` (como decía el catálogo) ni `chat.branch.reassigned`
     * (como decía el plan). Renombrarlo huerfanaría las filas históricas.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'chat.viewed' => 'Abrió la conversación',
        'chat.client.viewed' => 'Consultó la ficha del cliente',
        'chat.media.viewed' => 'Abrió un archivo adjunto',
        'chat.message.sent' => 'Respondió al cliente',
        'chat.message.retried' => 'Reintentó un mensaje que había fallado',
        'chat.message.sent_by_bot' => 'El bot respondió',
        'chat.history.read_by_bot' => 'El bot leyó la conversación',
        'chat.bot.toggled' => 'Cambió el estado del bot',
        'chat.contact.updated' => 'Editó los datos del cliente',
        'chat.reassigned' => 'Movió la conversación de sede',
        'chat.access.denied' => 'Intento de acceso rechazado',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'label' => self::LABELS[$this->action] ?? $this->action,
            // `null` = lo hizo el bot o el sistema, no una persona. La UI lo
            // rotula explícitamente en vez de mostrar un vacío.
            'actor' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
            ], null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
