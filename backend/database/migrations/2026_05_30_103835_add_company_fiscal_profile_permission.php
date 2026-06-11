<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Introduce el feature `company.fiscal_profile` y ajusta los permisos en pdn
 * SIN impactar a quienes ya editaban el perfil fiscal.
 *
 * Contexto: el perfil fiscal del emisor se movió de /company/dian a
 * /company/settings → "Información". El gate pasó de `dian.config` (que no
 * matcheaba ningún feature real → de-facto solo roles de sistema lo tocaban)
 * a este feature dedicado `company.fiscal_profile`.
 *
 * Modelo RBAC relevante:
 *  - owner/admin/employee son roles de sistema (`is_system=true`) y BYPASSEAN
 *    toda verificación de feature → conservan acceso al fiscal antes y después.
 *    Esta migración NO los afecta.
 *  - Los roles operativos (`is_system=false`: mesero, cajero, gerente, …) antes
 *    recibían 403 (el feature `dian.config` no existe). Para preservar esa
 *    restricción, este feature se siembra owner-only: el backfill les deja
 *    `[----]` (sin acceso). Nadie pierde acceso; se mantiene/endurece RBAC.
 *
 * Idempotente: re-ejecutable sin duplicar ni pisar grants manuales.
 *  - Feature: se inserta solo si no existe (por slug).
 *  - Templates: solo se insertan los role_type que falten.
 *  - company_role_permissions: solo se insertan filas para roles que aún no
 *    tienen una para este feature (respeta asignaciones manuales previas).
 *
 * Nota de cache: FeaturePermissionService cachea la matriz por (empresa,usuario)
 * con TTL flexible. No se purga: para roles operativos el resultado efectivo es
 * el mismo antes (sin fila → denegado) y después (fila en `false` → denegado),
 * así que no hay ventana de acceso indebido.
 */
return new class extends Migration
{
    private const FEATURE_SLUG = 'company.fiscal_profile';

    /**
     * Espejo de PermissionTemplateSeeder — owner + admin + employee (sistema)
     * y los operativos sembrables.
     *
     * @var list<string>
     */
    private const ROLE_TYPES = [
        'owner', 'admin', 'employee',
        'waiter', 'cook', 'cashier', 'manager',
        'accountant', 'marketing', 'inventory_manager', 'supervisor',
    ];

    public function up(): void
    {
        $now = now();

        // 1. Feature global (idempotente por slug).
        $featureId = DB::table('features')->where('slug', self::FEATURE_SLUG)->value('id');
        if ($featureId === null) {
            $featureId = (string) Str::uuid();
            DB::table('features')->insert([
                'id' => $featureId,
                'slug' => self::FEATURE_SLUG,
                'name' => 'Editar perfil fiscal',
                'description' => 'Permite editar el perfil fiscal del emisor (representante legal, CIIU, responsabilidades DIAN, contacto de facturación).',
                'group' => 'Empresa',
                'is_owner_only' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2. Templates por role_type — owner-only. Solo se insertan los faltantes
        //    (no se pisan valores existentes, por si se ajustaron a mano).
        $existingTemplateRoleTypes = DB::table('permission_templates')
            ->where('feature_id', $featureId)
            ->pluck('role_type')
            ->all();

        $templateRows = [];
        foreach (self::ROLE_TYPES as $roleType) {
            if (in_array($roleType, $existingTemplateRoleTypes, true)) {
                continue;
            }
            $isOwner = $roleType === 'owner';
            $templateRows[] = [
                'id' => (string) Str::uuid(),
                'role_type' => $roleType,
                'feature_id' => $featureId,
                'can_create' => $isOwner,
                'can_read' => $isOwner,
                'can_update' => $isOwner,
                'can_delete' => $isOwner,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($templateRows !== []) {
            DB::table('permission_templates')->insert($templateRows);
        }

        // 3. Backfill de company_role_permissions para empresas existentes.
        //    owner (rol de sistema con nombre de owner) → [RCUD]; el resto → [----].
        //    Los roles de sistema bypassean igual, así que para ellos la fila es
        //    declarativa; lo que de verdad importa es dejar a los operativos en
        //    `false` (= sin acceso, idéntico a antes de la migración).
        $ownerRoleName = (string) config('roles.role_names.owner', 'Propietario');

        $rolesWithRow = array_flip(
            DB::table('company_role_permissions')
                ->where('feature_id', $featureId)
                ->pluck('company_role_id')
                ->all()
        );

        DB::table('company_roles')
            ->select('id', 'name', 'is_system')
            ->orderBy('id')
            ->chunk(500, function ($roles) use ($featureId, $ownerRoleName, $rolesWithRow, $now): void {
                $rows = [];
                foreach ($roles as $role) {
                    if (isset($rolesWithRow[$role->id])) {
                        continue; // ya tiene fila (grant manual o re-run) — respetar.
                    }
                    $isOwnerRole = (bool) $role->is_system && $role->name === $ownerRoleName;
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'company_role_id' => $role->id,
                        'feature_id' => $featureId,
                        'can_create' => $isOwnerRole,
                        'can_read' => $isOwnerRole,
                        'can_update' => $isOwnerRole,
                        'can_delete' => $isOwnerRole,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    DB::table('company_role_permissions')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // Borrar el feature cascada a permission_templates y
        // company_role_permissions (FK onDelete cascade).
        DB::table('features')->where('slug', self::FEATURE_SLUG)->delete();
    }
};
