<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope que filtra automáticamente por la sede activa del request.
 *
 * Aplica solo si:
 *  - Hay un Request HTTP corriendo (no se aplica en consola/seeders/jobs sin contexto).
 *  - El request tiene `active_branch_id` inyectado por EnsureBranchAccess.
 *
 * Para escapar (reportes consolidados, jobs sin contexto), llamar `Model::withoutBranchScope()`
 * que provee el trait BelongsToBranch — este wrapper requiere permiso explícito en el caller
 * (ver controllers de métricas).
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound('request')) {
            return;
        }

        $branchId = app('request')->attributes->get('active_branch_id');

        if ($branchId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('branch_id'), $branchId);
    }
}
