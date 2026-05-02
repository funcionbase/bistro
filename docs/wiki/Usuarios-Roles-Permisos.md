# Usuarios, Roles y Permisos (RBAC)

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Modelo

```
User ── pertenece a ──► CompanyUser ◄── tiene rol ──► CompanyRole
                            │                              │
                            │                              ▼
                            │                  CompanyRolePermission
                            │                              │
                            ▼                              ▼
                  user_permission_overrides            Feature
```

| Tabla | Propósito |
|-------|-----------|
| `users` | Cuenta global del usuario |
| `companies` | Empresa (PK = `nit`) |
| `company_users` | Membresía: vincula `User`+`Company`+`CompanyRole`+`status` |
| `company_roles` | Rol dentro de una empresa (`is_system` para roles base) |
| `company_role_permissions` | Permiso CRUD por feature por rol |
| `features` | Catálogo de features del sistema |
| `permission_templates` | Plantilla por defecto que se aplica al crear roles base |
| `company_invitations` | Invitaciones por email pendientes |

---

## Roles del sistema

Definidos en `config/roles.php` y creados automáticamente al onboarding de empresa:

| Rol | `is_system` | Permisos | Notas |
|-----|-------------|----------|-------|
| `owner` | `true` | Todos | El creador de la empresa. Al menos uno siempre debe existir. No puede eliminarse del último owner. |
| `admin` | `true` | La mayoría (configurable) | Pensado para gerentes |
| `employee` | `true` | Solo lectura por defecto | Pensado para staff operativo |

Los roles `is_system=true`:
- **No pueden eliminarse** desde la UI ni la API.
- **No pueden modificarse** sus permisos.
- **Bypass del RBAC** en `EnsureFeaturePermission` (un `owner` siempre pasa cualquier `permission:...`).

---

## Features

Definidas en la tabla `features` con `slug` único:

| Slug | Nombre | Grupo |
|------|--------|-------|
| `orders` | Pedidos | operations |
| `menu` | Menú | operations |
| `hours` | Horarios | operations |
| `deliveries` | Domicilios | operations |
| `coupons` | Cupones | marketing |
| `reports` | Reportes | analytics |
| `metrics` | Métricas | analytics |
| `users` | Usuarios | admin |
| `roles` | Roles | admin |
| `chats` | Chats | communication |
| `company` | Empresa | admin |
| `billing` | Facturación | admin |

---

## Acciones (CRUD)

Para cada feature, un rol puede tener:

| Acción | Permite |
|--------|---------|
| `can_read` | Listar y consultar |
| `can_create` | Crear nuevos recursos |
| `can_update` | Actualizar recursos existentes |
| `can_delete` | Eliminar (soft o hard según el dominio) |

---

## Matriz de permisos por defecto (templates)

Estos son los valores aplicados por `permission_templates` al crear los roles base:

| Feature       | owner CRUD | admin CRUD | employee CRUD |
|---------------|------------|------------|---------------|
| `orders`      | RCUD       | RCUD       | R---          |
| `menu`        | RCUD       | RCUD       | R---          |
| `hours`       | RCUD       | RCUD       | R---          |
| `deliveries`  | RCUD       | RCUD       | R---          |
| `coupons`     | RCUD       | RCU-       | R---          |
| `reports`     | RCUD       | R---       | R---          |
| `metrics`     | RCUD       | R---       | R---          |
| `users`       | RCUD       | RCU-       | ----          |
| `roles`       | RCUD       | R---       | ----          |
| `chats`       | RCUD       | RCUD       | R---          |
| `company`     | RCUD       | -CU-       | ----          |
| `billing`     | RCUD       | R---       | ----          |

> Como los roles del sistema bypasean el RBAC, esta matriz refleja la **intención** documentada; en la práctica `owner` y `admin` con `is_system=true` no son verificados a nivel middleware. Roles personalizados sí se verifican estrictamente.

---

## Uso en rutas

```php
Route::middleware('permission:menu.read,read')->group(function () {
    Route::get('menus', [MenuController::class, 'index']);
});

Route::delete('coupons/{id}', [CouponController::class, 'destroy'])
    ->middleware('permission:coupons.delete,delete');
```

El primer argumento (`menu.read`, `coupons.delete`) es un identificador semántico; el segundo (`read`, `delete`) es la acción CRUD que se verifica.

---

## Endpoints

### Roles

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/api/v1/roles` | `roles.read,read` |
| `POST` | `/api/v1/roles` | `roles.create,create` |
| `PUT` | `/api/v1/roles/{id}` | `roles.update,update` |
| `DELETE` | `/api/v1/roles/{id}` | `roles.delete,delete` |
| `GET` | `/api/v1/features` | `roles.read,read` |

### Usuarios

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/api/v1/users` | `users.read,read` |
| `PUT` | `/api/v1/users/{id}/role` | `users.update,update` |
| `PUT` | `/api/v1/users/{id}/permissions` | `users.update,update` |
| `PATCH` | `/api/v1/users/{id}/status` | `users.update,update` |
| `DELETE` | `/api/v1/users/{id}` | `users.update,delete` |

### Invitaciones

| Método | Ruta | Permiso |
|--------|------|---------|
| `POST` | `/api/v1/invitations` | `users.update,create` |

---

## Invitaciones

Flujo:

1. Admin invita por email → `POST /api/v1/invitations { email, company_role_id }`.
2. Backend crea `company_invitations` con token único y `expires_at = now + 7 días`.
3. Email con link `https://app/.../enrollment/invited?token=...`.
4. Usuario invitado hace login (Google o registro) y luego `POST /api/v1/enrollment/invited { token }`.
5. Backend marca invitación como `accepted` y crea `company_users` con el rol pre-asignado.

Restricciones:
- Email ya miembro activo de la empresa → `409`.
- Email con invitación pendiente para la misma empresa → `409`.
- Token expirado → `422 INVITATION_EXPIRED`.

---

## Override de permisos por usuario

Un admin puede otorgar permisos extra a un usuario específico **sin cambiar su rol**:

```http
PUT /api/v1/users/{id}/permissions
Content-Type: application/json

{
  "overrides": [
    { "feature_id": 5, "can_read": true, "can_create": true }
  ]
}
```

Reglas:
- El **actor** no puede otorgar permisos que él mismo no tiene (validado en backend y reforzado en frontend con `disabledCheck`).
- Los overrides se almacenan en una tabla aparte y se mergean con los del rol al evaluar.

---

## Reglas de negocio

- **Último owner:** no se puede eliminar ni cambiar el rol del único `owner` activo de una empresa.
- **Auto-acción:** un usuario no puede modificar su propio rol ni desactivar su propia membresía.
- **Desactivación de membresía:** invalida la sesión JWT activa del usuario afectado vía `JwtService::invalidateUserActiveSession()`.
- **Roles de sistema:** no editables/eliminables; bypassean RBAC.

---

## Notas de seguridad

- El RBAC se evalúa en el middleware antes de llegar al controlador; ningún endpoint protegido se puede consumir sin pasar `EnsureFeaturePermission`.
- El frontend oculta navegación y botones según `permissions[]` del JWT, pero esto es UX defensivo: el backend siempre verifica.
- Los permisos se cachean en el JWT para evitar consultas repetidas; cualquier cambio de rol o de override **reemite el JWT** del usuario afectado.
