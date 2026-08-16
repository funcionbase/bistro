# Configuración personal

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Configuración del usuario autenticado, independiente de la empresa activa. Las páginas viven en `/settings/*` (renderizadas por el shell SPA #220) y los endpoints en `/api/v1/account/*`. Todas usan middleware Laravel `auth` (sesión web o JWT por cookie HttpOnly) **sin** `company.access` — un usuario en pleno enrolamiento (sin empresa) puede igualmente llegar a su perfil y apariencia.

Pantallas cubiertas:

| Ruta | Propósito | Auth |
|------|-----------|------|
| `/settings/profile` | Nombre, email, cédula + eliminar cuenta | `auth` |
| `/settings/appearance` | Tema claro / oscuro / sistema | `auth` (localStorage only) |
| `/settings/notifications` | Notificaciones push web (#149) | `auth` |
| `/settings/password` | **Deshabilitado** — Google OAuth only (HU #231) | `auth` |
| `/me` | Vista pública de perfil (read-only) | `auth` |
| `/me/perfil`, `/me/agenda` | Vistas extendidas (perfil de colaborador, turnos) | `auth` |

Desde la HU #231 toda la autenticación es vía Google OAuth, por lo que la pantalla `/settings/password` solo informa que no aplica. Los endpoints `PUT /password`, `PUT /settings/password` y `PUT /api/v1/account/password` responden **410 Gone** con `code: 'email_auth_disabled'` y loggean cualquier intento en `auth.legacy_endpoint_hit`.

---

## Modelo de datos

| Tabla | Campos clave |
|-------|--------------|
| `users` | `id`, `email` (unique), `name`, `first_name`, `last_name`, `cedula`, `password` (nullable — siempre `null` en OAuth), `email_verified_at`, `status`, `remember_token` |
| `user_acceptances` | `user_id`, `document_type` (`terms` / `privacy` / `contract`), `accepted_at`, `ip_address`, `user_agent` — append-only por Habeas Data CO |
| `user_active_tokens` | Tracking de tokens JWT activos por dispositivo (revocables) |
| `push_subscriptions` | `id`, `user_id`, `endpoint` (unique), `p256dh`, `auth`, `user_agent`, `created_at`, `last_seen_at` — #149 Web Push |

**Cascade en delete**: al borrar `users` se borran por FK `company_users`, `user_acceptances`, `user_active_tokens`, `push_subscriptions`. Las órdenes y chats históricos no se cascadean — quedan con `user_id=null` o sin asociación para no romper auditoría contable.

**No SoftDeletes en User**: hoy `users::delete()` es hard delete. Esto se debe a que la cuenta es la fuente para login OAuth — si el usuario quiere volver, simplemente entra de nuevo con Google y se crea una fila nueva.

---

## Permisos RBAC

Las rutas de `/settings/*` no requieren permisos finos — son del propio usuario. La autorización es por sesión / JWT (verificación de `auth()->user()` o `$payload['sub']`).

| Endpoint | Permission | Notas |
|----------|------------|-------|
| `PATCH /api/v1/account/profile` | — | El usuario edita su propia fila |
| `POST /api/v1/account/delete` | — | El usuario elimina su propia cuenta |
| `PATCH /settings/profile` | — | Versión legacy web |
| `DELETE /settings/profile` | — | Versión legacy web. Lleva además `password.confirm` (para flujos con password) |
| `POST /api/v1/push/subscriptions` | `notifications,create` (rate-limited 20/min) | #149 |
| `DELETE /api/v1/push/subscriptions` | `notifications,delete` | #149 |
| `GET /api/v1/push/subscriptions/me` | — (solo lectura del propio) | #149 |

**Sin impacto multi-tenant**: estas rutas operan sobre `users` y `push_subscriptions`, sin `company_nit`. No requieren `EnsureCompanyAccess` ni `EnsureBranchAccess`.

**Auto-eliminación bloqueada**: el usuario no puede eliminarse si es el último Propietario (`is_system=true`, slug owner) de alguna empresa. Validación lazy en el endpoint — no enforced por DB. Mensaje al usuario: "Transfiere la propiedad o elimina la empresa primero".

---

## Endpoints

| Método | Ruta | Auth | Notas |
|--------|------|------|-------|
| `GET` | `/settings` | `auth` | Redirect a `/settings/profile` |
| `GET` | `/settings/profile` | `auth` | Renderiza shell SPA (#220) |
| `GET` | `/settings/password` | `auth` | Renderiza pantalla informativa (OAuth only) |
| `GET` | `/settings/appearance` | `auth` | Renderiza |
| `GET` | `/settings/notifications` | `auth` | Renderiza |
| `PATCH` | `/api/v1/account/profile` | `jwt` | Edita nombre / email / cédula |
| `PUT` | `/api/v1/account/password` | `jwt` | **410 Gone** — `email_auth_disabled` |
| `POST` | `/api/v1/account/delete` | `jwt` | Elimina la cuenta del usuario actual |
| `PATCH` | `/settings/profile` | `auth` (web legacy) | Sigue para no romper named routes |
| `DELETE` | `/settings/profile` | `auth + password.confirm` | Legacy web |
| `PUT` | `/settings/password` | `auth` | **410 Gone** — legacy |
| `PUT` | `/password` | `auth` | **410 Gone** — legacy |
| `GET` | `/me` | `auth` | Vista pública (Inertia) |
| `GET` | `/api/v1/me` | `jwt` | API del perfil |
| `DELETE` | `/api/v1/me` | `jwt + password.confirm` | API eliminar cuenta |
| `POST` | `/api/v1/push/subscriptions` | `jwt + company.access + notifications,create` | #149 |
| `DELETE` | `/api/v1/push/subscriptions` | `jwt + company.access + notifications,delete` | #149 |
| `GET` | `/api/v1/push/subscriptions/me` | `jwt + company.access` | Lista de devices del usuario |

### `PATCH /api/v1/account/profile`

Controller: `App\Http\Controllers\Api\AccountController::updateProfile`. Usuario resuelto del JWT (`$payload['sub']`).

Body:

```json
{
  "name": "Cristian Marín",
  "email": "cristianmarint@gmail.com",
  "cedula": "1010100001"
}
```

Validación:

| Campo | Reglas |
|-------|--------|
| `name` | `required, string, min:2, max:255` |
| `email` | `required, string, email, max:255, unique:users,email,{id}` |
| `cedula` | `nullable, string, regex:/^[0-9]{5,20}$/` |

**Efecto colateral**: si `email` cambia, `email_verified_at` se nulifica. En el contexto OAuth-only esto puede dejar al usuario con email no verificado hasta el siguiente login Google que vuelva a verificarlo.

Response 200:

```json
{
  "data": {
    "id": 22,
    "name": "Cristian Marín",
    "email": "cristianmarint@gmail.com",
    "cedula": "1010100001",
    "email_verified_at": "2026-04-15T19:32:00Z"
  }
}
```

### `POST /api/v1/account/delete`

Controller: `App\Http\Controllers\Api\AccountController::destroy`.

Confirma con uno de dos métodos según si el usuario tiene password:

```php
if ($user->password !== null) {
    $request->validate(['password' => ['required', 'current_password']]);
} else {
    $request->validate(['confirm_email' => ['required', 'string', 'in:'.$user->email]]);
}
$user->delete();
```

- **Cuenta legacy con password**: pide `password` actual.
- **Cuenta OAuth (default)**: pide `confirm_email` que debe coincidir exactamente con el email del usuario.

Response 200: `{"message": "Cuenta eliminada."}`. El frontend invalida la sesión y redirige a `/`.

### `GET /api/v1/me`

```json
{
  "data": {
    "id": 22,
    "name": "Cristian Marín",
    "first_name": "Cristian",
    "last_name": "Marín",
    "email": "cristianmarint@gmail.com",
    "cedula": "1010100001",
    "email_verified_at": "2026-04-15T19:32:00Z",
    "active_company": {
      "nit": "1",
      "commercial_name": "SuperPasas",
      "role": {
        "name": "Propietario",
        "color": "#C0FD79",
        "is_system": true
      }
    },
    "memberships_count": 1
  }
}
```

### Push Notifications (#149)

| Método | Ruta | Body |
|--------|------|------|
| `GET` | `/api/v1/push/subscriptions/me` | — |
| `POST` | `/api/v1/push/subscriptions` | `{endpoint, keys: {p256dh, auth}, user_agent}` |
| `DELETE` | `/api/v1/push/subscriptions` | `{endpoint}` |

Throttle: 20 req/min en el prefijo `push/`. Ver `PWA-Push-Notifications.md` para detalles de Service Worker, VAPID, fan-out, etc.

---

## Flujos funcionales (paso a paso)

### Editar perfil

1. Usuario abre `/settings/profile`. La página renderiza el shell SPA que consume `GET /api/v1/me` para hidratar.
2. Edita `name` / `email` / `cedula`. Sanitización vía `SanitizedInput` con `maxLength=255` (alineado con backend).
3. Al guardar: `PATCH /api/v1/account/profile`.
4. Si email cambió: `email_verified_at` queda `null`. En la práctica el próximo callback de Google restablece la verificación.
5. Audit (lazy en algunos endpoints): `user.profile_updated` con `{changed_fields}`.

### Eliminar cuenta (OAuth)

1. Usuario abre tarjeta "Eliminar cuenta" en `/settings/profile`. Texto rojo y CTA destructivo.
2. UI muestra dialog: "Escribí tu correo para confirmar". Input con placeholder `cristianmarint@gmail.com`.
3. Submit: `POST /api/v1/account/delete` con `{confirm_email: '<email>'}`.
4. Backend valida `confirm_email` igual al email actual y ejecuta `users::delete()` (hard delete).
5. Cascade: borra `company_users`, `user_acceptances`, `user_active_tokens`, `push_subscriptions`.
6. UI: invalida JWT (cookie `bistro_jwt` con `expires` pasado) y redirige a `/`.

### Cambiar tema (apariencia)

`/settings/appearance` **no tiene endpoints API** — todo en `localStorage`. Hook `useAppearance`:

```ts
type Appearance = 'light' | 'dark' | 'system';

const useAppearance = () => {
  const [appearance, setState] = useState<Appearance>(() =>
    (localStorage.getItem('appearance') as Appearance) ?? 'system'
  );
  const updateAppearance = (mode: Appearance) => {
    localStorage.setItem('appearance', mode);
    setState(mode);
    applyTheme(mode);
  };
  return { appearance, updateAppearance };
};

const applyTheme = (mode: Appearance) => {
  const root = document.documentElement;
  if (mode === 'system') {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    root.classList.toggle('dark', prefersDark);
  } else {
    root.classList.toggle('dark', mode === 'dark');
  }
};
```

`<html class="dark">` activa los variants `dark:` de Tailwind. Sin server-side: el SSR de Inertia se hidrata en `light` y al montar React aplica el tema. Componentes:

- `appearance-tabs.tsx`: 3 botones segmentados (Light / Dark / System) usado en la página.
- `appearance-dropdown.tsx`: variante dropdown usada en el header móvil.

### Suscribir notificaciones push (#149)

1. Usuario abre `/settings/notifications`. Hook `usePushSubscription` reporta:
   - `isSupported`: `'serviceWorker' in navigator && 'PushManager' in window`.
   - `isStandalone`: si la PWA fue instalada al home screen.
   - `permission`: `default` / `granted` / `denied`.
   - `isSubscribed`: si hay subscription activa en este dispositivo.
2. Estados visuales:
   - **No soportado**: explanación, sin botón muerto.
   - **Bloqueado**: instructivo de cómo desbloquear en configuración del navegador.
   - **Sin permiso (default)**: botón "Activar notificaciones".
   - **Activado**: botón "Desactivar".
3. Al activar:
   - `Notification.requestPermission()`.
   - `navigator.serviceWorker.register('/sw.js')`.
   - `registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: VAPID_PUBLIC_KEY})`.
   - `POST /api/v1/push/subscriptions` con `{endpoint, keys, user_agent}`. Throttle 20/min.
4. Componente `PushSubscriptionsList` lista los devices (cada subscription tiene `user_agent` legible) con CTA "Quitar este dispositivo" (`DELETE /api/v1/push/subscriptions`).
5. Los demás dispositivos se quitan abriendo la app desde esos dispositivos — un dispositivo no puede revocar a otro.

### Intento de cambiar contraseña (deshabilitado)

1. Usuario abre `/settings/password`. UI muestra explanación: "Tu cuenta usa Google para iniciar sesión. No hay contraseña que cambiar".
2. Si por alguna razón el cliente envía `PUT /api/v1/account/password` (o `PUT /password`), backend responde **410 Gone**:

```json
{
  "message": "El cambio de contraseña está deshabilitado. Tu cuenta usa Google para iniciar sesión.",
  "code": "email_auth_disabled"
}
```

Y loggea `auth.legacy_endpoint_hit` con path, método, user_id e IP/UA para detectar clientes desactualizados o scrapers.

---

## Componentes frontend

| Archivo | Propósito |
|---------|-----------|
| `pages/settings/profile.tsx` | Información del perfil + tarjeta "Eliminar cuenta" |
| `pages/settings/appearance.tsx` | Selector Light / Dark / System |
| `pages/settings/notifications.tsx` | Estado push + lista de devices (#149) |
| `pages/settings/password.tsx` | Pantalla informativa (OAuth only) |
| `pages/me/index.tsx` | Vista de solo lectura del perfil |
| `pages/me/perfil.tsx`, `pages/me/agenda.tsx` | Vistas extendidas: perfil de colaborador HHRR (#182) y agenda de turnos |
| `layouts/settings/layout.tsx` | Subnav lateral de configuración personal |
| `components/heading-small.tsx` | Encabezado consistente de cada sección |
| `components/delete-user.tsx` | Card destructiva con dialog de confirmación |
| `components/appearance-tabs.tsx`, `appearance-dropdown.tsx` | Selectores de tema |
| `components/notifications/push-subscriptions-list.tsx` | Lista de devices con acción "Quitar" |
| `hooks/use-appearance.ts` | Hook `useAppearance` con persistencia en `localStorage` |
| `hooks/use-push-subscription.ts` | Hook `usePushSubscription` que orquesta SW + PushManager |
| `lib/use-logout.ts` | Logout global + invalidación de cookie JWT |

Tokens del DS obligatorios. La página de notificaciones es mobile-first (apila vertical) con tokens `bg-card`, `border-border`, `text-muted-foreground`. Cero hex hardcoded.

---

## Eventos de auditoría

Catálogo en `bistro/backend/constants/AUDIT_EVENTS.md`:

| Acción | Disparador | Data |
|--------|-----------|------|
| `user.profile_updated` | `AccountController::updateProfile` | `{changed_fields}` |
| `user.deleted` | `AccountController::destroy` o `ProfileController::destroy` | `{self_deletion: true, had_password: bool}` |
| `auth.legacy_endpoint_hit` | Endpoints 410 Gone | `{path, method, user_id, ip, ua}` |
| `push.subscribed` | `PushSubscriptionController::store` | `{endpoint_hash, user_agent}` |
| `push.unsubscribed` | `PushSubscriptionController::destroy` | `{endpoint_hash}` |

No hay evento para apariencia (cliente-only).

---

## Edge cases y empty states

- **Email cambiado a uno ya existente**: 422 con `validation.email.unique`. Frontend muestra "Ese email ya está vinculado a otra cuenta".
- **Cédula con formato no numérico**: 422 con `validation.cedula.regex`. El regex es `^[0-9]{5,20}$` — sin guiones, espacios ni letras.
- **Eliminar siendo último Propietario**: 422 con `user.cannot_delete_last_owner` y mensaje "Transfiere la propiedad o elimina la empresa primero".
- **Browser sin soporte para Service Worker / Push**: UI muestra estado "No soportado" con sugerencia "Actualiza el navegador o instala la app".
- **Permiso de notificaciones denegado a nivel OS**: incluso con `granted` en el browser, iOS Safari pre-PWA puede no entregar. UI sugiere "Instala la app al home screen para recibir avisos en iOS".
- **Endpoint push con throttle**: 20 req/min en el prefijo `push/`. Si se excede, 429 con `Retry-After`. UI muestra "Demasiados intentos, esperá un momento".
- **JWT expirado durante edición de perfil**: 401 con `code: 'token_expired'`. `lib/api.ts` intercepta y redirige a login (Google OAuth).
- **Subscription con endpoint duplicado**: `endpoint` es UNIQUE en `push_subscriptions`. `POST` con endpoint existente hace upsert silencioso (refresca `last_seen_at`).
- **Tema "system" cambiando en tiempo real**: el hook no se suscribe a `prefers-color-scheme` change events — el usuario debe recargar para ver el cambio si su SO alterna entre claro/oscuro automáticamente.
- **Usuario sin empresa activa**: las rutas `/settings/*` siguen funcionando (sin `company.access`). `/me` muestra `active_company: null` y `memberships_count: 0`.
- **Cuenta legacy con password**: hoy excepcional. Si existe (`users.password IS NOT NULL`), el flujo de delete cambia a "confirma con tu password" en lugar de "escribí tu email". El frontend detecta la rama leyendo flag `has_password` desde `/api/v1/me`.

---

## Cross-references

- Constants: `bistro/backend/constants/AUDIT_EVENTS.md`, `FEATURES_INDEX.md`, `PERMISSIONS_CATALOG.md`.
- Backend: `app/Http/Controllers/Api/{AccountController,MeController,PushSubscriptionController}.php`, `app/Http/Controllers/Settings/{ProfileController,PasswordController}.php`, `app/Http/Controllers/Auth/{GoogleAuthController,VerifyEmailController}.php`, `routes/{auth.php,settings.php}`, `app/Models/{User,UserAcceptance,UserActiveToken,PushSubscription}.php`.
- Frontend: `src/pages/settings/{profile,password,appearance,notifications}.tsx`, `src/pages/me/{index,perfil,agenda}.tsx`, `src/layouts/settings/layout.tsx`, `src/hooks/{use-appearance,use-push-subscription}.ts`, `src/components/{delete-user,appearance-tabs,appearance-dropdown,heading-small}.tsx`, `src/components/notifications/push-subscriptions-list.tsx`.
- Wiki relacionado: `Autenticación.md`, `Onboarding.md`, `PWA-Push-Notifications.md`, `Usuarios-Roles-Permisos.md`.
