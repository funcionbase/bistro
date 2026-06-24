<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BUG-029: backfill missing company_role_permissions entries for is_system=true roles.
 *
 * When new features are added (e.g., cash_register.manage / cash_register.operate_others
 * in commit 055df76), permission_templates gets new rows but existing companies'
 * system roles (Propietario / Administrador / Empleado) don't receive the corresponding
 * company_role_permissions entries. This migration closes that gap idempotently.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleTypes = ['owner', 'admin', 'employee'];

        $nameToType = [];
        foreach ($roleTypes as $type) {
            $name = config("roles.role_names.{$type}");
            if (is_string($name)) {
                $nameToType[$name] = $type;
            }
        }

        $systemRoles = DB::table('company_roles')
            ->where('is_system', true)
            ->whereIn('name', array_keys($nameToType))
            ->select('id', 'name')
            ->get();

        if ($systemRoles->isEmpty()) {
            return;
        }

        $templates = DB::table('permission_templates')
            ->whereIn('role_type', $roleTypes)
            ->select('role_type', 'feature_id', 'can_create', 'can_read', 'can_update', 'can_delete')
            ->get()
            ->groupBy('role_type');

        $now = now();
        $rows = [];

        foreach ($systemRoles as $role) {
            $roleType = $nameToType[$role->name] ?? null;
            if ($roleType === null || ! $templates->has($roleType)) {
                continue;
            }

            $existing = DB::table('company_role_permissions')
                ->where('company_role_id', $role->id)
                ->pluck('feature_id')
                ->flip()
                ->all();

            foreach ($templates[$roleType] as $tpl) {
                if (isset($existing[$tpl->feature_id])) {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'company_role_id' => $role->id,
                    'feature_id' => $tpl->feature_id,
                    'can_create' => $tpl->can_create,
                    'can_read' => $tpl->can_read,
                    'can_update' => $tpl->can_update,
                    'can_delete' => $tpl->can_delete,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('company_role_permissions')->insert($chunk);
        }
    }

    public function down(): void
    {
        // ponytail: no rollback — permissions are additive; manual rollback via
        // DELETE WHERE feature_id IN (new features) AND created_at >= migration date.
    }
};
