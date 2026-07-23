<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F1 — permisos `whatsapp.manage_branch_channels` y `chats.audit`
 * (plan 8-whatsapp.md §7.3).
 *
 * - `whatsapp.manage_branch_channels`: con canales por sede (§5.2) hay que
 *   distinguir "conectar el número de la empresa" (owner/admin, ya cubierto por
 *   `whatsapp.connect`) de "conectar el número de MI sede". Se compone con
 *   `whatsapp.connect` + acceso a la sede, igual que `chats.reassign_branch`.
 *   Default owner-only: el caso real es delegarlo a un jefe de sede, pero nadie
 *   lo recibe automáticamente.
 * - `chats.audit`: ver la pestaña "Actividad" de una conversación (§7.6). Owner
 *   y admin sí, operador no — quien es auditado no administra su auditoría. Lo
 *   crea F1 aunque lo consuma F2b: sembrarlo dos veces haría que el segundo
 *   backfill pisara grants ajustados a mano en el intervalo.
 *
 * `roles:sync-templates` no cubre roles `is_system=false`, por eso el backfill de
 * `company_role_permissions` va acá (regla de .claude/rbac.md).
 *
 * Idempotente: re-ejecutable sin duplicar ni pisar grants manuales.
 *
 * Nota de caché: `FeaturePermissionService` cachea la matriz por (empresa,
 * usuario). No se purga — para los roles operativos el resultado efectivo es el
 * mismo antes (sin fila → denegado) y después (fila en `false` → denegado), así
 * que no hay ventana de acceso indebido.
 */
return new class extends Migration
{
    /**
     * Espejo de PermissionTemplateSeeder.
     *
     * @var list<string>
     */
    private const ROLE_TYPES = [
        'owner', 'admin', 'employee',
        'waiter', 'cook', 'cashier', 'courier', 'manager',
        'accountant', 'marketing', 'inventory_manager', 'supervisor',
    ];

    /**
     * Los permisos a sembrar. `admin_read` marca los que el admin recibe en modo
     * lectura; el resto es owner-only.
     *
     * @var list<array{slug: string, name: string, description: string, group: string, admin_read: bool}>
     */
    private const FEATURES = [
        [
            'slug' => 'whatsapp.manage_branch_channels',
            'name' => 'Gestionar el WhatsApp de una sede',
            'description' => 'Permite conectar y desconectar el número de WhatsApp de una sede a la que el usuario tenga acceso. Se compone con "Conectar WhatsApp".',
            'group' => 'WhatsApp',
            'admin_read' => false,
        ],
        [
            'slug' => 'chats.audit',
            'name' => 'Ver la actividad de una conversación',
            'description' => 'Permite ver quién abrió, respondió y reasignó cada conversación. Pensado para supervisión: no se otorga a quien atiende los chats.',
            'group' => 'Chats',
            'admin_read' => true,
        ],
    ];

    public function up(): void
    {
        $now = now();
        $ownerRoleName = (string) config('roles.role_names.owner', 'Propietario');
        $adminRoleName = (string) config('roles.role_names.admin', 'Administrador');

        foreach (self::FEATURES as $feature) {
            $featureId = DB::table('features')->where('slug', $feature['slug'])->value('id');

            if ($featureId === null) {
                $featureId = (string) Str::uuid();
                DB::table('features')->insert([
                    'id' => $featureId,
                    'slug' => $feature['slug'],
                    'name' => $feature['name'],
                    'description' => $feature['description'],
                    'group' => $feature['group'],
                    'is_owner_only' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->seedTemplates($featureId, $feature['admin_read'], $now);
            $this->backfillCompanyRoles($featureId, $feature['admin_read'], $ownerRoleName, $adminRoleName, $now);
        }
    }

    public function down(): void
    {
        // Cascada a permission_templates y company_role_permissions (FK cascade).
        DB::table('features')
            ->whereIn('slug', array_column(self::FEATURES, 'slug'))
            ->delete();
    }

    /**
     * Templates por `role_type`. Solo inserta los faltantes: no pisa valores que
     * alguien haya ajustado a mano.
     */
    private function seedTemplates(string $featureId, bool $adminRead, DateTimeInterface $now): void
    {
        $existing = DB::table('permission_templates')
            ->where('feature_id', $featureId)
            ->pluck('role_type')
            ->all();

        $rows = [];
        foreach (self::ROLE_TYPES as $roleType) {
            if (in_array($roleType, $existing, true)) {
                continue;
            }

            [$create, $read, $update, $delete] = $this->permsFor($roleType, $adminRead);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'role_type' => $roleType,
                'feature_id' => $featureId,
                'can_create' => $create,
                'can_read' => $read,
                'can_update' => $update,
                'can_delete' => $delete,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('permission_templates')->insert($rows);
        }
    }

    /**
     * Backfill de `company_role_permissions` para las empresas que ya existen.
     *
     * Los roles de sistema bypassean toda verificación en runtime, así que para
     * ellos la fila es declarativa; lo que importa es dejar a los roles
     * operativos (`is_system=false`) explícitamente en `false`.
     */
    private function backfillCompanyRoles(
        string $featureId,
        bool $adminRead,
        string $ownerRoleName,
        string $adminRoleName,
        DateTimeInterface $now,
    ): void {
        $rolesWithRow = array_flip(
            DB::table('company_role_permissions')
                ->where('feature_id', $featureId)
                ->pluck('company_role_id')
                ->all()
        );

        DB::table('company_roles')
            ->select('id', 'name', 'is_system')
            ->orderBy('id')
            ->chunk(500, function ($roles) use ($featureId, $adminRead, $ownerRoleName, $adminRoleName, $rolesWithRow, $now): void {
                $rows = [];

                foreach ($roles as $role) {
                    if (isset($rolesWithRow[$role->id])) {
                        continue; // grant manual o re-run: respetar.
                    }

                    $isSystem = (bool) $role->is_system;
                    $roleType = match (true) {
                        $isSystem && $role->name === $ownerRoleName => 'owner',
                        $isSystem && $role->name === $adminRoleName => 'admin',
                        default => 'custom',
                    };

                    [$create, $read, $update, $delete] = $this->permsFor($roleType, $adminRead);

                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'company_role_id' => $role->id,
                        'feature_id' => $featureId,
                        'can_create' => $create,
                        'can_read' => $read,
                        'can_update' => $update,
                        'can_delete' => $delete,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('company_role_permissions')->insert($rows);
                }
            });
    }

    /**
     * @return array{bool, bool, bool, bool} [create, read, update, delete]
     */
    private function permsFor(string $roleType, bool $adminRead): array
    {
        if ($roleType === 'owner') {
            return [true, true, true, true];
        }

        if ($roleType === 'admin' && $adminRead) {
            return [false, true, false, false];
        }

        return [false, false, false, false];
    }
};
