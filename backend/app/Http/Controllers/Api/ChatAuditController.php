<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatAuditLogResource;
use App\Models\AuditLog;
use App\Models\Chat;
use App\Services\Chat\ChatAuditLogger;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Pestaña "Actividad" de una conversación (plan 8-whatsapp.md §7.6).
 *
 *   GET /api/v1/chats/{id}/audit
 *
 * Permiso `chats.audit`: por template lo tienen owner y admin, no el operador —
 * quien es auditado no administra su auditoría.
 *
 * NO es un módulo global de auditoría: responde por UNA conversación. Un
 * explorador de `audit_logs` completo no existe hoy y nadie lo pidió.
 */
class ChatAuditController extends Controller
{
    /** Suficiente para reconstruir un turno de trabajo sin paginar. */
    private const LIMIT = 50;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly ChatAuditLogger $auditLogger,
    ) {}

    public function index(Request $request, string $id): JsonResponse
    {
        // Doble verificación (§7.5 capa 5): la ruta ya exige
        // `permission:chats.audit,read`, y acá se repite. `hasPermission` compone
        // el slug como "{grupo}.{accion}", así que ('chats', 'audit') resuelve
        // `chats.audit` — NO es el permiso `chats.read` del resto del módulo.
        $this->permissionService->assertPermission($request, 'chats', 'audit');

        $companyNit = $request->attributes->get('active_company_nit');

        // El chat se resuelve PRIMERO y scopeado por empresa: sin esto, pedir la
        // actividad de un chat ajeno confirmaría su existencia aunque la lista
        // saliera vacía. Falla → 404, igual que el resto del módulo (§7.5 capa 6).
        // `uuid` malformado -> 22P02 -> 500 con el error de la base. Se trata
        // como inexistente para que la respuesta sea siempre el mismo 404.
        $chat = Str::isUuid($id) ? Chat::forCompany($companyNit)->find($id) : null;

        if ($chat === null) {
            $this->auditLogger->log(
                action: 'chat.access.denied',
                data: [
                    'chat_id' => $id,
                    'attempted_company_nit' => $companyNit,
                    'route' => $request->route()?->getName(),
                ],
                request: $request,
                dedupeKey: $id,
            );

            abort(404);
        }

        // Doble filtro deliberado: por `auditable` (las filas que apuntan al
        // modelo) Y por `company_nit` dentro de `data`. El segundo es el que usa
        // el índice parcial y el que impide que una fila mal escrita en otra
        // empresa se cuele por coincidencia de UUID.
        $logs = AuditLog::query()
            ->with('user:id,name')
            ->where('auditable_type', Chat::class)
            ->where('auditable_id', $chat->id)
            ->where('data->company_nit', $companyNit)
            // El filtro por `action` NO es cosmético: `audit_logs_chat_company_idx`
            // es un índice PARCIAL con este mismo predicado, y PostgreSQL solo
            // puede usarlo si la consulta implica el predicado. Sin esta línea el
            // índice existe pero es inservible para el único query que lo motivó
            // — verificado con EXPLAIN. De paso acota la pestaña a las acciones
            // del módulo en vez de a cualquier fila que apunte a un Chat.
            ->where(function ($q): void {
                $q->where('action', 'like', 'chat.%')
                    ->orWhere('action', 'like', 'whatsapp.%');
            })
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'data' => ChatAuditLogResource::collection($logs),
            'meta' => ['limit' => self::LIMIT, 'count' => $logs->count()],
        ]);
    }
}
