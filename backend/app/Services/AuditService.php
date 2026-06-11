<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Registra eventos auditables en la tabla audit_logs.
 *
 * Formato del campo data: array{before?: array, after?: array, actor_id?: int, ...contexto adicional}.
 * El registro captura user_id, action, auditable_type/id, ip_address y user_agent.
 * Se usa desde controladores y servicios; el request se obtiene del contenedor si no se pasa.
 */
class AuditService
{
    /**
     * Record an auditable action for a user.
     *
     * @param  array<string, mixed>  $data
     */
    public function log(
        string $action,
        ?User $user = null,
        ?Model $auditable = null,
        array $data = [],
        ?Request $request = null
    ): AuditLog {
        $request ??= request();

        // Multi-sede (#117): registramos siempre dos identificadores cuando estén disponibles:
        //  - branch_id: la sede del recurso auditado (si el modelo tiene ese atributo).
        //  - actor_active_branch_id: la sede que el usuario tenía activa al ejecutar la acción.
        // Permite reconstruir intentos cross-sede aunque ocurran en sedes distintas.
        $resourceBranchId = $auditable && property_exists($auditable, 'attributes') && isset($auditable->branch_id)
            ? $auditable->branch_id
            : null;
        $actorActiveBranchId = $request?->attributes->get('active_branch_id');

        if ($resourceBranchId !== null && ! array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $resourceBranchId;
        }
        if ($actorActiveBranchId !== null && ! array_key_exists('actor_active_branch_id', $data)) {
            $data['actor_active_branch_id'] = $actorActiveBranchId;
        }

        return AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'data' => empty($data) ? null : $data,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
