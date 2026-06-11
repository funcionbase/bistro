# Checklist RBAC — qué tocar cuando…

> **Para qué sirve**: cada vez que un PR introduce, renombra o elimina un
> permiso o un rol, **pegar el sub-checklist que aplique al cuerpo del PR** y
> marcarlo fila por fila. Asegura que ningún archivo espejo queda atrás.
> Cubrir los 8 escenarios canónicos del proyecto.

> **Fuente de verdad ejecutable**: ver cada `.md` hermano para los archivos
> reales que tocás. Este checklist solo te dice cuáles son y en qué orden.

---

## Antes de empezar (cualquier cambio RBAC)

- [ ] Leí [`README.md`](./README.md) de `bistro/backend/constants/`.
- [ ] Identifiqué qué `.md` cubre el dominio que estoy tocando
      (ROLES_SYSTEM / ROLES_TEMPLATES / ROLES_DEMO / PERMISSIONS_CATALOG /
      COURIER_MODE / BRANCH_RBAC).
- [ ] Confirmé que el cambio NO contradice una regla de bypass owner
      ([`ROLES_SYSTEM.md`](./ROLES_SYSTEM.md)).

---

## 1) Agregar un permiso nuevo (`<grupo>.<acción>`)

- [ ] **`FeatureSeeder.php`**: agregar entrada con `slug`, `name` (corto),
      `description`, `group`. Si es owner-only, agregar `'is_owner_only' => true`.
- [ ] **`PermissionTemplateSeeder.php`**: declarar defaults por `role_type`.
      Mínimo: owner siempre `RCUD`. Definir explícito para admin y employee.
      Si aplica, agregar al `match` de waiter/cook/cashier.
- [ ] **Migración** (si requiere persistir en BD inmediatamente, no solo
      esperar al próximo `db:seed`): emitir un seeder one-off o ejecutar
      `php artisan db:seed --class=FeatureSeeder` post-deploy.
- [ ] **¿Afecta el sidebar o el courier-mode?**
  - Si la presencia del permiso indica que el usuario debe ver navegación
    completa → agregar a `FULL_NAV_PERMISSIONS` en **ambos**:
    - [ ] `bistro/backend/app/Support/PostLoginRedirect.php`
    - [ ] `bistro/frontend/src/lib/courier-mode.ts`
  - Si NO (es un permiso que un courier podría tener legítimamente) → no
    tocar courier-mode.
- [ ] **Si el permiso es asignable manualmente** → verificar que aparece
      automáticamente en `UserPermissionsEditor` (sale de `Feature::all()`).
      Si es `is_owner_only=true`, el editor lo oculta cuando el actor no es
      owner — ya implementado.
- [ ] **Validación en backend**: agregar `middleware('permission:<slug>,<action>')`
      a la ruta correspondiente en `routes/api.php` (o `routes/web.php`).
      Acciones: `read`, `create`, `update`, `delete`.
- [ ] **Validación en frontend** (si la UI debe ocultar / deshabilitar el
      botón): usar `usePermissions().has('<slug>')` desde
      `resources/js/hooks/use-permissions.ts`.
- [ ] **Auditoría**: si la acción muta estado, llamar `AuditService::log(...)`
      con metadata que reconstruya la operación. `branch_id` se inyecta
      automático.
- [ ] **Actualizar [`PERMISSIONS_CATALOG.md`](./PERMISSIONS_CATALOG.md)**:
      agregar fila al grupo correspondiente con los defaults.
- [ ] **Si es sensible cross-branch** → actualizar también
      [`BRANCH_RBAC.md`](./BRANCH_RBAC.md) sección "Permisos sensibles
      owner-only".
- [ ] **Wiki público** (`docs/wiki/Usuarios-Roles-Permisos.md`): si el slug es
      visible para el usuario final del producto (owner de empresa), agregar
      al catálogo del wiki. Si es interno, omitir.
- [ ] **Commit**: `feat(rbac): add <slug>` (separado de la feature que lo usa
      si son distintos commits lógicos).

## 2) Renombrar un permiso

- [ ] Migración que ejecute `UPDATE features SET slug='<nuevo>' WHERE slug='<viejo>'`.
- [ ] Actualizar `FeatureSeeder.php` con el nuevo slug.
- [ ] Actualizar `PermissionTemplateSeeder.php` (los `match` que usen el slug literal).
- [ ] Buscar y reemplazar referencias literales:
      `grep -rn "'<viejo>'" bistro/` y
      `grep -rn '"<viejo>"' bistro/frontend/src/`.
- [ ] Si está en `FULL_NAV_PERMISSIONS` → renombrar en **ambos** archivos
      ([`COURIER_MODE.md`](./COURIER_MODE.md) pares espejo).
- [ ] **Invalidar caches** post-deploy: el `JwtService` puede cachear el set
      de slugs en el token. Forzar reissue (logout) o bumpear la versión del
      cache si aplica.
- [ ] Actualizar [`PERMISSIONS_CATALOG.md`](./PERMISSIONS_CATALOG.md) (cambiar
      fila).
- [ ] Comunicar a otros devs: rompe permisos asignados en BD si la migración
      no cubre todas las filas.

## 3) Eliminar un permiso

- [ ] Migración de borrado: `DELETE FROM permission_templates WHERE feature_id IN
      (SELECT id FROM features WHERE slug='<slug>')` y luego
      `DELETE FROM features WHERE slug='<slug>'`.
- [ ] Quitar entrada de `FeatureSeeder.php` y de `PermissionTemplateSeeder.php`.
- [ ] Quitar de `FULL_NAV_PERMISSIONS` (PHP + TS) si estaba.
- [ ] Quitar referencias literales en frontend (`grep` en `resources/js/`).
- [ ] Quitar `middleware('permission:<slug>,...)` de rutas afectadas
      (probablemente la ruta se elimina también).
- [ ] Quitar fila de [`PERMISSIONS_CATALOG.md`](./PERMISSIONS_CATALOG.md).
- [ ] Quitar mención en wiki público si aplicaba.
- [ ] Commit: `chore(rbac): remove <slug>`.

## 4) Agregar un rol del sistema nuevo (`is_system=true`)

> **Caso raro**. Los tres canónicos (owner/admin/employee) están en el
> diseño base. Agregar uno nuevo implica revisar el bypass del RBAC en 4
> capas (ver [`ROLES_SYSTEM.md`](./ROLES_SYSTEM.md)).

- [ ] `config/roles.php`: agregar al `system_roles` (env `SYSTEM_ROLES`),
      `role_names`, `role_colors`.
- [ ] `PermissionTemplateSeeder.php`: agregar el nuevo `role_type` al
      `foreach` con su rama del `match`.
- [ ] Revisar bypass `is_system` en:
  - `FeaturePermissionService.php` (¿el nuevo rol debe tener bypass total?).
  - `EnsureFeaturePermission.php` (idem).
  - `RoleController::authorizeManagerRole` (¿quién puede asignarlo?).
  - `use-permissions.ts` (flag `isSystem` en frontend).
- [ ] Revisar la regla del último owner inviolable: ¿el nuevo rol también
      debe ser inviolable?
- [ ] `RestauranteFlexySeeder::seedRoles`: si va en el demo, agregar
      `seedSystemRoleFromTemplate($companyNit, '<roleType>', config('roles.role_colors.<roleType>'))`.
- [ ] Actualizar [`ROLES_SYSTEM.md`](./ROLES_SYSTEM.md).
- [ ] Actualizar wiki público (`docs/wiki/Usuarios-Roles-Permisos.md`).

## 5) Agregar un rol de plantilla nuevo (`is_system=false`, operativo)

- [ ] `config/roles.php`: agregar al `role_names` y `role_colors` (NO al
      `system_roles`).
- [ ] `PermissionTemplateSeeder.php`: agregar el `role_type` al `foreach` y
      declarar su map (o agregar a un sub-bloque tipo `$waiterMap`/`$cookMap`).
- [ ] `SyncRoleTemplatesCommand`: agregar al default del flag `--role`
      (`waiter,cook,cashier,<nuevo>`).
- [ ] Correr `php artisan roles:sync-templates --dry-run` para verificar.
- [ ] Actualizar [`ROLES_TEMPLATES.md`](./ROLES_TEMPLATES.md) con la nueva
      sub-tabla.

## 6) Agregar un rol demo (solo para `RestauranteFlexySeeder`)

> Solo si el rol es específico del demo y NO debería instanciarse en empresas
> reales (ej. `Domiciliario` actual). Si va a ser instanciable, seguir el
> escenario #5 en su lugar.

- [ ] `RestauranteFlexySeeder::seedRoles`: agregar bloque
      `CompanyRole::updateOrCreate(['company_nit' => …, 'name' => '<nombre>'], [...])`
      con `is_system=false` y color hex propio.
- [ ] `RestauranteFlexySeeder::seedRoles`: llamar `syncRolePermissions(...)`
      con los slugs exactos. **Pensar bien qué set le das**: si tiene
      `deliveries.self_assign` y nada de `FULL_NAV_PERMISSIONS`, activa
      courier mode (ver [`COURIER_MODE.md`](./COURIER_MODE.md)).
- [ ] Crear / asignar usuario demo del rol en `seedUsers` +
      `assignUserToRole`.
- [ ] Actualizar [`ROLES_DEMO.md`](./ROLES_DEMO.md) con la nueva sub-sección
      (tabla de permisos + razón de por qué no es `role_type`).

## 7) Cambiar defaults de un rol existente (system o template)

- [ ] Editar el `match` o el map de `PermissionTemplateSeeder.php`.
- [ ] Si es un `role_type` operativo y hay empresas vivas con el rol creado:
      correr `php artisan roles:sync-templates --role=<tipo>` post-deploy
      para reconciliar.
- [ ] Actualizar la columna correspondiente en
      [`PERMISSIONS_CATALOG.md`](./PERMISSIONS_CATALOG.md) y/o
      [`ROLES_TEMPLATES.md`](./ROLES_TEMPLATES.md).
- [ ] Si el cambio retira permiso que se asignaba a admin por default y
      había empresas que dependían de él, mencionar en el PR para que sea
      decisión consciente del owner.

## 8) Cambiar permisos sensibles cross-branch (#192)

- [ ] Editar el `match` de admin en `PermissionTemplateSeeder.php` (mantener
      el set en `[false,false,false,false]` salvo decisión explícita de
      delegarlos).
- [ ] Actualizar [`BRANCH_RBAC.md`](./BRANCH_RBAC.md) sección "Permisos
      sensibles owner-only".
- [ ] Actualizar [`PERMISSIONS_CATALOG.md`](./PERMISSIONS_CATALOG.md) fila.

---

## Verificación final del PR (cualquier cambio RBAC)

- [ ] `vendor/bin/pint --dirty --format agent` limpio.
- [ ] `npx tsc --noEmit` limpio (en `bistro/frontend/`).
- [ ] `npx eslint resources/js` limpio si tocó archivos TS.
- [ ] Si tocó schema/seeders: `php artisan migrate:fresh --seed --force` corre
      limpio localmente.
- [ ] Smoke manual: `php artisan tinker --execute "App\\Models\\Feature::where('slug','<slug-nuevo>')->exists();"` retorna `true`.
- [ ] Sub-checklist correspondiente de arriba pegado al cuerpo del PR y
      marcado fila por fila.
- [ ] Si el cambio es no-trivial, dejar comentario en el issue origen con
      resumen de lo tocado y enlace al PR (regla raíz `CLAUDE.md` —
      "Registro en comentarios de GitHub como historial").

## Notas operativas

- **N-instance**: si el cambio agrega un schedule (ej.
  `Schedule::command('roles:sync-templates')`), debe llevar
  `->onOneServer()->withoutOverlapping()` (regla raíz `CLAUDE.md`).
- **Docker** (cuando exista): si en algún momento se introduce `Dockerfile`
  que copie `bistro/backend/` entera, agregar `bistro/backend/constants/` al
  `.dockerignore`. Hoy no aplica.

> Última revisión: 2026-05-18 (#201).
