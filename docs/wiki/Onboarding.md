# Onboarding

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Flujo de enrolamiento que toma un usuario recién creado vía Google OAuth y lo deja con una empresa activa, una sede operativa y un rol Propietario asignado. El proceso comprende cinco pasos:

1. **Login con Google OAuth** — única vía de acceso (HU #231).
2. **Datos personales del usuario** (`/enrollment/user`) — nombre, cédula, aceptación legal.
3. **Datos de empresa + verificación de propiedad** (`/enrollment/company`) — NIT, banca, sede principal y evidencia documental (#154).
4. **Sede inicial** — creada automáticamente como `principal` dentro del enrolamiento.
5. **Plan inicial** — `Subscription` con snapshot inmutable del plan default vigente (#246).

La empresa nace en estado `pending_activation` y queda bloqueada por el middleware `company.verified` hasta que un operador externo la transicione a `verified` mediante el workflow `Company Status (per env)`. Mientras tanto el frontend cae en `pages/company/under-review.tsx`.

---

## Modelo de datos

| Tabla | Campos clave |
|-------|--------------|
| `users` | `id`, `email`, `name`, `first_name`, `last_name`, `cedula`, `password` (nullable — OAuth), `email_verified_at`, `status` (`pending_enrollment` / `pending_company` / `active` / `completed`) |
| `companies` | `nit` (PK string), `commercial_name`, `legal_name`, `bank_id`, `account_number`, `account_type`, `breb_key`, `qr_code_path`, `status` (`pending_activation` / `verified` / `rejected` / `active`) |
| `company_users` | `user_id`, `company_nit`, `company_role_id`, `status` |
| `company_roles` | `id`, `company_nit`, `name`, `is_system`, `color` — 3 system roles (`owner`/`admin`/`employee`) + 8 templates operativos |
| `company_role_permissions` | `company_role_id`, `feature_id`, `can_create/read/update/delete` |
| `branches` | `id`, `company_nit`, `name`, `slug` (`principal`), `is_default`, `business_type_id` (#237) |
| `branch_users` | `branch_id`, `user_id`, `granted_by_user_id`, `granted_at` |
| `enrollment_proofs` | 1:1 con `companies`. Guarda key S3, MIME real, tamaño, filename, uploader_user_id |
| `user_acceptances` | `user_id`, `document_type` (`terms` / `privacy` / `contract`), `accepted_at`, `ip_address`, `user_agent` |
| `company_invitations` | `company_nit`, `email`, `token`, `role`, `status` (`pending` / `accepted` / `expired`), `expires_at` |
| `subscriptions` | `company_nit`, `billing_plan_id`, `plan_name_snapshot`, `plan_price_snapshot`, `plan_features_snapshot` (JSON), `plan_snapshot_at`, `status` (`active`) |

**Storage**: el documento de propiedad va al disco `s3_documents` con key `enrollment-proofs/{nit}/{timestamp}-{slug}.{ext}`. El QR opcional va al disco default (`s3` en QA/PDN, `local` en dev) bajo `companies/qr-codes/...`.

---

## Permisos RBAC

El enrolamiento ocurre **antes** de tener empresa activa, por lo que la mayoría de rutas usan `jwt` puro sin `company.access`. El gate de verificación (#154) sí aplica una vez la empresa existe:

| Etapa | Auth | Notas |
|-------|------|-------|
| `POST /api/v1/enrollment/user` | `jwt` (cookie HttpOnly) | Requiere `users.status='pending_enrollment'` |
| `POST /api/v1/enrollment/company` | `jwt` | Requiere `users.status='active'` (paso personal completo) |
| `POST /api/v1/enrollment/invited` | `jwt` | Match por `auth()->user()->email == invitation.email` |
| `GET /api/v1/enrollment/proof/preview` | `jwt + company.access` (sin `company.verified`) | Solo uploader o rol owner. URL firmada ≤ 15 min |
| Mutaciones operativas | `jwt + company.access + company.verified` | Bloqueadas hasta `status ∈ config('companies.verified')` |

**Bypass owner**: el rol owner (`is_system=true`) nace con todos los permisos vía `permission_templates`. Las rutas de enrolamiento no consultan permisos finos — la sola creación del owner es el seed.

**Asignación automática**: tras crear la empresa se siembran 11 roles totales (3 sistema: owner/admin/employee + 8 templates: waiter/cook/cashier/manager/accountant/marketing/inventory_manager/supervisor). Los 8 templates llegan con `is_system=false` para que el owner los edite desde `/identities/roles`.

---

## Endpoints

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| `GET` | `/auth/google` | público | Inicia OAuth (Socialite). Genera state y redirige a Google |
| `GET` | `/auth/google/callback` | público | Callback de Google. Crea o reusa `users`, emite JWT en cookie HttpOnly |
| `POST` | `/api/v1/enrollment/user` | `jwt` | Cierra paso personal (nombre, cédula, TOS, privacidad). Reissue JWT |
| `POST` | `/api/v1/enrollment/company` | `jwt` | Crea empresa, sede principal, roles, membresía y subscription. Multipart |
| `POST` | `/api/v1/enrollment/invited` | `jwt` | Acepta invitación pendiente por token, vincula al usuario al rol pre-asignado |
| `GET` | `/api/v1/enrollment/proof/preview` | `jwt + company.access` | URL firmada S3 (≤ 15 min) del documento de propiedad |
| `GET` | `/api/v1/companies/active` | `jwt + company.access` | Disponible aunque `status='pending_activation'` para pintar `under-review.tsx` |

### Cuerpo de `POST /api/v1/enrollment/user`

```json
{
  "first_name": "Cristian",
  "last_name": "Marín",
  "cedula": "1112792674",
  "accept_tos": true,
  "accept_privacy": true,
  "accepted_documents": [
    {"type": "tos",     "version": "1.2.0"},
    {"type": "privacy", "version": "1.0.5"}
  ]
}
```

**FormRequest**: `App\Http\Requests\Enrollment\UserEnrollmentRequest`. Reglas: `first_name|last_name required|string|max:100`, `cedula required|string|max:20|unique:users,cedula,{id}`, `accept_tos|accept_privacy required|accepted`.

### Cuerpo de `POST /api/v1/enrollment/company`

`Content-Type: multipart/form-data`. Campos:

| Campo | Tipo | Validación |
|-------|------|------------|
| `nit` | string | `required, max:20, unique:companies,nit` |
| `commercial_name` | string | `required, max:100` |
| `legal_name` | string | `required, max:150` |
| `bank_id` | int | `required, exists:banks,id` |
| `account_number` | string | `required, max:30` |
| `account_type` | enum | `required, in:corriente,ahorros` |
| `breb_key` | string | `nullable, max:50` |
| `qr_code` | file | `nullable, mimes:png,jpg,jpeg, max:5120` (KB) |
| `proof_document` | file | `required, mimes:pdf,doc,docx,jpg,jpeg,png, max:10240` (#154) |
| `accept_contract` | bool | `required, accepted` |
| `main_branch_name` | string | `nullable, max:100` — default `Sede principal` |
| `main_branch_address` | string | `nullable` |
| `main_branch_city` | string | `nullable` |
| `main_branch_business_type` | string | `nullable, exists:business_types,slug` — default `restaurant` (#237) |
| `promo_code` | string | `nullable` — aplica si llega vía `?promo=` en la URL (#246) |

**FormRequest**: `App\Http\Requests\Enrollment\CompanyEnrollmentRequest`.

### Respuesta `201`

```json
{
  "authenticated": true,
  "company": {
    "nit": "900123456-7",
    "commercial_name": "SuperPasas",
    "status": "pending_activation"
  },
  "enrollment_step": "complete"
}
```

JWT nuevo se entrega vía `Set-Cookie: flexyflow_jwt=...; HttpOnly; Secure; SameSite=Lax`.

### Errores comunes

| Status | Código | Causa |
|--------|--------|-------|
| `422` | `enrollment.already_completed` | El usuario ya pasó el paso personal |
| `422` | `validation.nit.unique` | NIT ya existe |
| `422` | `validation.proof_document.required` | Sin documento de propiedad (#154) |
| `422` | `validation.proof_document.mimes` | Formato no permitido |
| `422` | `validation.qr_code.max` | QR > 5 MB |
| `422` | `invitation.expired` | `expires_at < now()` en `POST /enrollment/invited` |
| `422` | `invitation.already_accepted` | Invitación ya consumida |
| `403` | `code: company_not_verified` | Mutación operativa con `status != verified` |

---

## Flujos funcionales (paso a paso)

### Paso 1 — Login con Google OAuth

1. Usuario abre `/login`. El controller redirige 302 a `auth.google` con `reason=email_auth_disabled` si vino por una ruta legacy.
2. `GoogleAuthController::redirect` arma el flow Socialite con scopes `openid profile email` y guarda `state` en sesión.
3. Google devuelve a `/auth/google/callback`. El controller:
   - Busca o crea `users` por `email`. Si es nuevo, `status='pending_enrollment'`, `password=null`, `email_verified_at=now()` (Google ya verificó).
   - Emite JWT con `sub=user.id`, sin `active_company_nit` aún.
   - Setea cookie HttpOnly `flexyflow_jwt`.
4. Frontend redirige según `users.status`:
   - `pending_enrollment` → `/enrollment/user`
   - `active` sin empresa → `/enrollment/company`
   - `completed` → `/dashboard`

### Paso 2 — Datos personales (`/enrollment/user`)

Wizard de 3 pasos en `pages/enrollment/user.tsx`:

1. **Datos personales**: `first_name`, `last_name`, `cedula`. Sanitización vía `SanitizesInput` (categorías `plain_text_short`).
2. **Aceptación legal**: dos checkboxes con links al wiki externo (`useBootstrap().data.legalUrls.tos` y `.privacy`). Versiones congeladas en estado React al cargar.
3. **Vinculación**: dos opciones:
   - "Crear nueva empresa" → al confirmar, `users.status` queda en `active` y redirige a `/enrollment/company`.
   - "Aceptar invitación pendiente" → si `company_invitations.email == user.email && status=pending`, banner aparece. Al confirmar va a `/enrollment/invited`.

`UserEnrollmentController::store` envuelve todo en `DB::transaction`:
- Update `users` con datos personales + `status='active'`.
- Inserta `user_acceptances` por cada documento (sin snapshot — la fuente es el wiki externo versionado).
- Reissue JWT con `enrollment_step='pending_company'`.
- `AuditService::log('user.enrolled', ..., {accepted_documents, documents_source: 'external_wiki'})`.

### Paso 3 — Verificación de propiedad de la empresa (#154)

Wizard de 2 pasos en `pages/enrollment/company.tsx`:

1. **Contrato de servicio**: link a `bootstrap.legalUrls.contract` (target `_blank`). Checkbox obligatorio.
2. **Datos de empresa + evidencia**: campos descritos arriba.

El campo `proof_document` es el corazón de #154. Acepta PDF, Word (`.doc`/`.docx`), JPG y PNG hasta **10 MB**. Validado por MIME real (`mimetypes:`) tanto en cliente como en backend — el botón "Registrar empresa" queda deshabilitado sin archivo válido.

Documentos típicos aceptados: Certificado de Cámara de Comercio, RUT, cédula del representante legal. La revisión humana decide qué cuenta como evidencia suficiente.

**Persistencia**: `EnrollmentProofService::store` sube al disco `s3_documents` con key `enrollment-proofs/{nit}/{timestamp}-{slug}.{ext}`, crea la fila `enrollment_proofs` (1:1 con `companies`) y emite `AuditService::log('enrollment.proof_uploaded', ..., {key, mime, size, filename, uploader_user_id})`. La conservación es soft-delete obligatorio (DIAN 5–10 años).

**Estados canónicos** (`config/companies.php`):

| Estado | Significado | Acceso |
|--------|-------------|--------|
| `pending_activation` | Default tras enrolamiento | Solo `/company/under-review` + lectura de `/api/v1/companies/active` |
| `verified` | Habilitada | Operación normal |
| `rejected` | Verificación fallida | `under-review.tsx` con tono crítico + contacto soporte |
| `active` | Legacy (equivale a `verified`) | Operación normal |

### Paso 4 — Sede principal (automática)

Dentro de la misma transacción de `CompanyEnrollmentController`:

- `Branch::create([slug='principal', is_default=true, business_type_id=<vertical>])`.
- `BranchUser::create([branch_id, user_id=owner, granted_by_user_id=owner, granted_at=now()])`.
- Siembra `prep_areas` del vertical (salvo `dark_store`).
- Siembra las 4 estaciones KDS canónicas (`caliente`, `fria`, `barra`, `fritos`) vía `KdsStation::seedDefaultsForBranch`.

El owner puede renombrar la sede principal o crear nuevas desde `/company/branches` luego del enrolamiento.

### Paso 5 — Plan inicial (#246)

Si existe un `BillingPlan::default()` vigente, se crea `Subscription` con snapshot inmutable de:

- `plan_name_snapshot`, `plan_price_snapshot`, `plan_features_snapshot` (JSON).
- `plan_tax_regime_snapshot`, `plan_tax_rate_snapshot`.
- `plan_snapshot_at`, `starts_at=now()`, `status='active'`.

El snapshot es la fuente para reportes históricos: si flexyflow cambia el precio del plan default mañana, las suscripciones existentes no se afectan.

Si el frontend pasó `?promo=<slug>`, `PromoCodeService::applyToCompany` aplica el descuento. Si el código es inválido, **no bloquea el enrolamiento** — solo loggea.

### Paso 6 — Post-enrolamiento

Después del `DB::commit`:

- `SendCompanyRegistrationWelcomeEmailJob::dispatch($user->id, $company->nit)` — correo al owner.
- `SendCompanyPendingActivationOpsAlertJob::dispatch($company->nit, $user->id)` — alerta al equipo flexyflow para iniciar la revisión.

Ambos jobs son `ShouldQueue + ShouldBeUnique` con columnas de tracking (`welcome_email_sent_at`, `ops_alert_sent_at`). El `after_commit:true` global de `config/queue.php` garantiza que solo se encolan si la transacción commitea OK.

El JWT se reissue con `companies=[{nit, commercial_name, status}]` y se entrega como cookie. Frontend redirige a `/dashboard`, donde el middleware `EnsureCompanyVerified` redirige a `/company/under-review` por el `status='pending_activation'`.

### Onboarding por invitación (`/enrollment/invited`)

Flujo alterno para usuarios cuya cuenta es nueva pero ya tienen `company_invitations` pendiente:

1. `POST /api/v1/enrollment/invited` con `{invitation_token}`.
2. `InvitedEnrollmentController` valida:
   - `invitation.email == auth()->user()->email` (mismatch → 403).
   - `invitation.status == 'pending'`.
   - `invitation.expires_at >= now()` (si pasó → marca `expired`, 422).
3. En transacción: inserta membership con `company_role_id` pre-asignado, marca invitación `accepted`, update `users.status='completed'`.
4. Reissue JWT con la empresa nueva como activa.

Default TTL invitación: 7 días (`INVITATION_TTL_DAYS`).

---

## Componentes frontend

| Archivo | Propósito |
|---------|-----------|
| `pages/enrollment/user.tsx` | Wizard 3 pasos (datos, legal, vinculación) |
| `pages/enrollment/company.tsx` | Wizard 2 pasos (contrato, empresa + evidencia) |
| `pages/enrollment/company-guard.tsx` | Guard que redirige según `users.status` |
| `pages/company/under-review.tsx` | Pantalla bloqueante (#154) — hero 2-col, tokens de status (`warning`/`critical`) |
| `components/ui/hero-headline.tsx`, `hero-panel.tsx` | Hero layout compartido con onboarding |
| `lib/company-status.ts` | Label legible por estado (`pending_activation` → "En revisión") |
| `lib/api.ts` | Intercepta respuestas con `code: 'company_not_verified'` y redirige a `/company/under-review` |
| `lib/shared-data.ts` | Hook `useSharedData()` con `activeCompany` |
| `lib/use-logout.ts` | Logout desde la pantalla de revisión |

El campo de archivo (`proof_document`) soporta drag/drop y selector tradicional, valida MIME y tamaño en cliente, y deshabilita el botón submit hasta que haya un archivo válido. La sanitización de texto usa `SanitizedInput` con `maxLength=maxBytes` exactos del backend.

---

## Eventos de auditoría

Emitidos por `AuditService::log` (agrega `branch_id` y `actor_active_branch_id` automáticamente cuando aplica):

| Acción | Disparador | Data |
|--------|-----------|------|
| `user.enrolled` | `UserEnrollmentController::store` | `{accepted_documents, documents_source: 'external_wiki'}` |
| `company.created` | `CompanyEnrollmentController::store` | `{nit, status, promo_code_applied}` |
| `enrollment.proof_uploaded` | `EnrollmentProofService::store` | `{key, mime, size, filename, uploader_user_id}` |
| `invitation.accepted` | `InvitedEnrollmentController::store` | `{invitation_id, company_nit, role}` |
| `auth.company.selected` | Reissue de JWT con `active_company_nit` | `{nit}` |
| `company.status_changed_external` | Workflow `Company Status (per env)` | `{from, to, reason, source: 'github_action', workflow_run_url}` |

Catálogo completo en `bistro/backend/constants/AUDIT_EVENTS.md`.

---

## Edge cases y empty states

- **Usuario con email duplicado**: si Google devuelve un email ya existente, `GoogleAuthController::callback` reusa la fila. Si `status='completed'` salta enrolamiento; si está en estado intermedio, retoma el paso pendiente.
- **NIT duplicado entre empresas**: bloqueado por `unique:companies,nit`. El owner debe usar otra empresa o esperar a que el dueño anterior la libere (no hay flujo de "tomar" empresas — soporte manual).
- **Documento de propiedad corrupto/ilegible**: el workflow externo marca `status='rejected'` con `reason`. El frontend muestra el tono crítico en `under-review.tsx` con CTA a `soporte@flexyflow.com`. El owner puede reintentar contactando soporte; el workflow soporta `rejected → pending_activation`.
- **Pérdida de conexión durante el upload**: la transacción se revierte completa (sin empresa parcial, sin S3 huérfano). El frontend re-habilita el botón.
- **Empresa sin sede default**: no debería ocurrir — la sede `principal` se crea en la misma transacción. Si pasa (data drift), las mutaciones operativas fallan con 403 por `EnsureBranchAccess`.
- **Invitación a email no registrado**: el invitado debe primero loguearse con Google. Tras el callback, frontend detecta `users.status='pending_enrollment'` y le ofrece el paso personal antes del `/enrollment/invited`.
- **Plan default ausente**: si `BillingPlan::default()` devuelve `null`, la empresa nace sin `Subscription`. El módulo de billing tolera la ausencia y muestra "Sin plan asignado" hasta que el owner elija uno.
- **Promo code inválido**: log silencioso. La empresa queda creada sin promo aplicada.
- **Empresa en `pending_activation` indefinidamente**: el frontend permite cerrar sesión desde `under-review.tsx`. No hay timeout automático — la revisión es manual.

---

## Cross-references

- Constants: `bistro/backend/constants/PERMISSIONS_CATALOG.md`, `ROLES_SYSTEM.md`, `ROLES_TEMPLATES.md`, `AUDIT_EVENTS.md`, `BRANCH_RBAC.md`, `FEATURES_INDEX.md`.
- Backend: `app/Http/Controllers/Enrollment/{UserEnrollmentController,CompanyEnrollmentController,InvitedEnrollmentController,EnrollmentProofController}.php`, `app/Http/Controllers/Auth/GoogleAuthController.php`, `app/Services/{EnrollmentProofService,CompanySettingsService,JwtService,PromoCodeService}.php`, `app/Http/Middleware/EnsureCompanyVerified.php`.
- Frontend: `src/pages/enrollment/{user,company,company-guard}.tsx`, `src/pages/company/under-review.tsx`, `src/lib/company-status.ts`, `src/lib/api.ts`.
- Wiki relacionado: `Empresas.md`, `Autenticación.md`, `Usuarios-Roles-Permisos.md`, `Multi-tenancy.md`.
