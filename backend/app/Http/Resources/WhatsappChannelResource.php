<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CompanyWhatsappAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canal de WhatsApp tal como lo ve la pantalla `Empresa → WhatsApp` (§8.2).
 *
 * NUNCA expone `evo_token_encrypted`, `inbound_secret_encrypted` ni
 * `access_token_encrypted`: el modelo ya los tiene en `$hidden`, pero acá se
 * enumeran los campos uno por uno en vez de volcar el modelo, que es lo que
 * hace que agregar una columna secreta mañana no la publique sola (§12).
 *
 * `last_message_at` y `chats_count` los inyecta el controlador con una consulta
 * agregada — no son columnas de la tabla.
 *
 * @mixin CompanyWhatsappAccount
 */
class WhatsappChannelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            // El nombre de la sede es lo que el usuario reconoce; el UUID no le
            // dice nada. `whenLoaded` evita el N+1 si el caller no hizo el with.
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'scope' => $this->isCompanyWide() ? 'company' : 'branch',
            'label' => $this->label,
            'status' => $this->status,
            'phone_e164' => $this->phone_e164,
            'display_name' => $this->display_name,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'disconnected_at' => $this->disconnected_at?->toIso8601String(),
            'last_connection_check_at' => $this->last_connection_check_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'last_message_at' => $this->whenNotNull($this->last_message_at ?? null),
            'chats_count' => (int) ($this->chats_count ?? 0),
            // El operador no puede escribir por un canal caído: la UI apaga el
            // compositor con esto en vez de deducirlo del `status`, que tiene
            // seis valores y crecería divergente entre back y front (§8.6).
            'can_send' => $this->canSendViaEvolution(),
        ];
    }
}
