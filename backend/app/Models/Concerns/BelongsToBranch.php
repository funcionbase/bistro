<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait para modelos que pertenecen a una sede.
 *
 * Aplica el global scope BranchScope en boot. Para consultas cross-sede (reportes
 * consolidados con permiso `metrics.view_all_branches`), usar `withoutBranchScope()`.
 *
 * Convención: branch_id es uuid, NOT NULL, FK->branches.id (restrictOnDelete).
 *
 * Auto-asignación de branch_id al crear:
 *  1) Si el modelo ya trae branch_id, no hace nada.
 *  2) Lee `active_branch_id` del request (runtime con JWT).
 *  3) Como fallback (seeders/jobs sin request), lee `static::$seederBranchId`,
 *     que se setea con `BelongsToBranch::setSeederBranch(string $branchId)`.
 *  4) Si no encuentra ninguno, deja branch_id NULL — la BD lanzará constraint
 *     violation al insertar y el caller verá un error claro.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function (Model $model): void {
            if (! empty($model->getAttribute('branch_id'))) {
                return;
            }

            $branchId = null;

            if (app()->bound('request')) {
                $branchId = app('request')->attributes->get('active_branch_id');
            }

            if ($branchId === null && app()->bound('belongs_to_branch.seeder_branch_id')) {
                $branchId = app('belongs_to_branch.seeder_branch_id');
            }

            if ($branchId !== null) {
                $model->setAttribute('branch_id', $branchId);
            }
        });
    }

    /**
     * Setea el branch_id de fallback para seeders/jobs sin contexto HTTP.
     * Persistido en el container para que sea global a todos los modelos que
     * usen el trait (las static properties de un trait son per-class, no globales).
     * Pasar `null` para resetear (e.g. al cambiar de empresa en un seeder
     * multi-tenant).
     */
    public static function setSeederBranch(?string $branchId): void
    {
        if ($branchId === null) {
            if (app()->bound('belongs_to_branch.seeder_branch_id')) {
                app()->forgetInstance('belongs_to_branch.seeder_branch_id');
            }

            return;
        }

        app()->instance('belongs_to_branch.seeder_branch_id', $branchId);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    /**
     * Escapa el global scope. Úsese SOLO con justificación explícita.
     *
     * Convención: cada call-site de `withoutBranchScope()` o
     * `withoutGlobalScope(BranchScope::class)` debe tener arriba un comentario
     * o un PHPDoc del método/clase que explique POR QUÉ se justifica el escape.
     * Casos legítimos:
     *
     *  - Flujos públicos sin JWT (menú QR, table-join, webhooks WhatsApp):
     *    el request no tiene `active_branch_id` y la sede se resuelve por
     *    otros medios (slug, phone lookup, `unique(company_nit, ...)`).
     *  - CRM cross-sede (CrmService, ClientController): un cliente final es
     *    único a nivel empresa, no de sede; lookup de identidad atraviesa
     *    sedes deliberadamente.
     *  - Validaciones de uniqueness por empresa (default warehouse,
     *    asignaciones únicas): chequeos administrativos que necesitan ver
     *    toda la empresa para evitar duplicados.
     *  - Reportes consolidados con permiso `metrics.view_all_branches`
     *    (middleware `AllowConsolidatedBranches`).
     *  - Jobs / consola sin contexto HTTP: el scope no se aplicaría igual
     *    porque `app('request')` no resuelve a un request real.
     *
     * Antipatrón: usar `withoutBranchScope()` para "evitar problemas" sin
     * entender la implicación. Cada escape expone datos cross-sede al actor
     * de la query; si el caso no encaja en uno de los casos legítimos,
     * NO se debe usar.
     *
     * @return Builder<static>
     */
    public static function withoutBranchScope(): Builder
    {
        return static::query()->withoutGlobalScope(BranchScope::class);
    }
}
