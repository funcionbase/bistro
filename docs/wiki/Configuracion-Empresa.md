# Configuración de empresa

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Conjunto de pantallas para administrar la configuración consolidada de la empresa activa. Toda la configuración vive a nivel de empresa (`company_nit`), no de sede — para configuración por sede ver `Empresas.md` (multi-sede #117) y el módulo de Sedes dedicado.

Pantallas cubiertas:

| Ruta | Propósito | Permiso base |
|------|-----------|--------------|
| `/company/settings` | Información administrativa (banca, branding, fiscal) | `company.update,update` |
| `/company/preferences` | Preferencias operativas (regional, pedidos, notificaciones, branding del menú público) | `company.update,read` / `update` |
| `/company/dian` | Configuración de facturación electrónica DIAN | `dian.config,read` / `update` |
| `/company/whatsapp` | Conexión y preferencias del número de WhatsApp Cloud API | `whatsapp.read` / `whatsapp.connect` / `whatsapp.swap_phone` / `whatsapp.disconnect` |
| `/company/kds` | Estaciones KDS y tokens de tablet | `kds_stations,*` |
| `/company/printers` | Impresoras térmicas por sede | `printers.manage,*` |
| `/company/branches` | Sedes (creación, edición, archivado) | `branches.manage,*` |

`/company/settings` y `/company/preferences` se gestionan vía el `CompanySettingsController` con allowlist de claves; los demás módulos tienen controllers dedicados. Todas las rutas requieren `company.access + company.verified` salvo `GET /api/v1/companies/active` que se mantiene disponible en `pending_activation` para pintar `under-review.tsx` (#154).

---

## Modelo de datos

| Tabla | Campos clave |
|-------|--------------|
| `companies` | `nit` (PK), `commercial_name`, `legal_name`, `bank_id`, `account_number`, `account_type`, `breb_key`, `qr_code_path`, `logo_path`, `status`, `tax_regime`, `default_tax_rate`, `default_tax_label`, `tax_included_in_price` |
| `company_settings` | `company_nit`, `key`, `value` (JSON) — almacén KV con allowlist |
| `banks` | `id`, `name`, `code` — catálogo público |
| `branches` | Ver `Empresas.md` y módulo Sedes |
| `dian_fiscal_profiles` | 1:1 con `companies`. Razón social fiscal, dirección fiscal, régimen, responsabilidades, código de actividad económica |
| `dian_resolutions` | `id`, `company_nit`, `prefix`, `from`, `to`, `current`, `valid_from`, `valid_until`, `technical_key` |
| `dian_provider_configs` | `company_nit`, `provider` (`facturalo`, `factus`, etc.), credenciales cifradas, `environment` (`habilitacion` / `produccion`) |
| `dian_default_recipients` | Adquirente por defecto para POS sin cliente identificado |
| `dian_electronic_documents` | Facturas, notas crédito, FEV emitidas. Inmutables (DIAN 5/10 años) |
| `company_whatsapp_accounts` | 1:1 con `companies`. `provisioning_mode` (`embedded_signup` / `naas`), `status`, `waba_id`, `phone_number_id` (UNIQUE global), `phone_e164`, `access_token` (encrypted) |
| `company_whatsapp_account_events` | Append-only. `event_type` (`signup_completed`, `swap_phone`, `disconnected`, ...) |
| `whatsapp_verification_codes` | OTP para acciones sensibles. `action`, `code_hash`, `reject_token`, `attempts`, `expires_at`, `consumed_at`, `rejected_at` |
| `meta_platform_credentials` | Una fila por ambiente. `app_id`, `app_secret` (encrypted), `system_user_token` (encrypted), `webhook_verify_token` (encrypted) |

---

## Permisos RBAC

Toda la configuración de empresa está scopeada por `EnsureCompanyAccess` (inyecta `active_company_nit`). Permisos relevantes:

| Slug | Owner | Admin | Notas |
|------|-------|-------|-------|
| `company.update` | RCUD | RCUD | Datos administrativos + preferencias |
| `billing.read` | RCUD | RCUD | Lista de facturas + plan en `/company/settings` tab "Facturación" |
| `company.fiscal_profile` | RCUD | ---- | Perfil fiscal del emisor (DV, representante legal, CIIU, responsabilidades, municipio, contacto/dirección de facturación). Se edita desde `/company/settings` → "Información". **Owner-only por template** (admin/operativos = ----); owner/admin/employee bypassean por `is_system`, los roles operativos quedan restringidos. |
| `dian.config` | RCUD | RCUD | Resoluciones, provider, default recipient (escritura). **Ya no** cubre el perfil fiscal. |
| `dian.default_recipient` | RCUD | RCUD | Update/delete del adquirente por defecto |
| `dian.recipients` | RCUD | --U- | Lookup + completado DIAN-profile del cliente (operativo) |
| `dian.documents` | RCUD | RCUD | CRUD de electronic documents |
| `dian.print` | --U- | --U- | Reimprimir documento |
| `whatsapp.read` | RCUD | RCUD | Ver estado de la cuenta + preferencias |
| `whatsapp.connect` | RC-- | RC-- | Disparar Embedded Signup o NaaS request |
| `whatsapp.swap_phone` | ---D | (sensible) | Owner-only por `WhatsappAccountPolicy::swapPhone` |
| `whatsapp.disconnect` | ---D | (sensible) | Owner-only por `WhatsappAccountPolicy::disconnect` |
| `kds_stations.*` | RCUD | (sensible — asignable manualmente) | Ver `Cocina.md` |
| `printers.manage` | RCUD | RCUD | Ver `Empresas.md` y módulo Impresoras |
| `branches.manage` | RCUD | RCUD | Ver módulo Sedes |

**Defensa en profundidad WhatsApp**: aunque `swap_phone` / `disconnect` están marcados como sensibles, `WhatsappAccountPolicy` verifica por nombre de rol owner además del slug. Si alguien manipula la matriz para dar `whatsapp.disconnect` a un admin, la policy lo rechaza.

**Reissue de JWT**: actualizar `commercial_name` reemite el JWT para que el sidebar (`RestaurantIdentity`) muestre el nombre nuevo sin refresh.

---

## Endpoints

### `/company/settings` — Información

| Método | Ruta | Permission |
|--------|------|------------|
| `GET` | `/api/v1/companies/active` | `jwt + company.access` (sin permission — solo membresía) |
| `GET` | `/api/v1/company` | `company.update,read` |
| `PUT` \| `POST` | `/api/v1/company` | `company.update,update` |

Controller: `App\Http\Controllers\Api\CompanyController`. FormRequest: `App\Http\Requests\Company\UpdateCompanyRequest`.

Campos editables:

| Campo | Tipo | Validación | Notas |
|-------|------|------------|-------|
| `commercial_name` | string | `required, max:100` | Reemite JWT |
| `legal_name` | string | `required, max:150` | Razón social |
| `bank_id` | int | `required, exists:banks,id` | Selector dinámico |
| `account_number` | string | `required, max:30` | Numérico |
| `account_type` | enum | `required, in:corriente,ahorros` | — |
| `breb_key` | string | `nullable, max:50` | BREB (Banco de la República) |
| `logo` | file | `nullable, mimes:png,jpg,jpeg,webp,svg, max:5120` | 5 MB |
| `qr_code` | file | `nullable, mimes:png,jpg,jpeg, max:5120` | 5 MB |

**Campo NO editable**: `nit` es la PK y nunca se actualiza. Cualquier intento se ignora silenciosamente — no está en el FormRequest.

**Sección Impuestos** (`config/taxes.php` + `companies.tax_*`):

| Campo | Tipo | Persistencia |
|-------|------|--------------|
| `tax_regime` | enum: `simple` / `inc_8` / `iva_19` / `iva_5` / `iva_exento` / `custom` | `companies.tax_regime` |
| `default_tax_rate` | decimal(5,2) | `companies.default_tax_rate` |
| `default_tax_label` | varchar(60) | `companies.default_tax_label` |
| `tax_included_in_price` | bool | `companies.tax_included_in_price` |

El selector de régimen autocompleta `rate` y `label` desde `tax_presets`. Modo `custom` permite ingreso libre. Cada plato puede tener override desde `dish-form-modal.tsx`. **Snapshot inmutable a nivel de orden**: al crear una orden se persisten `tax_regime`, `tax_included_in_price`, `snapshot_default_tax_rate` y `tax_rate` efectivo ponderado para coherencia DIAN.

**Tab "Facturación"** (icono `Receipt`): requiere `billing.read,read`. Lista facturas (`GET /api/v1/billing/invoices`), plan actual (`GET /api/v1/billing/subscription`), banner de mora si hay overdue.

### `/company/preferences` — Preferencias

| Método | Ruta | Permission |
|--------|------|------------|
| `GET` | `/api/v1/companies/settings` | `company.update,read` |
| `GET` | `/api/v1/companies/settings/{key}` | `company.update,read` |
| `PATCH` | `/api/v1/companies/settings` | `company.update,update` |

Controller: `App\Http\Controllers\Api\CompanySettingsController`. Servicio: `App\Services\CompanySettingsService`. FormRequest: `App\Http\Requests\Settings\UpdateCompanySettingsRequest`.

**Allowlist** (`CompanySettingsService::ALLOWED_KEYS`). Sólo estas claves se aceptan; cualquier otra → 422 con `key.invalid`:

```php
public const ALLOWED_KEYS = [
    // Regional
    'timezone', 'currency', 'language',
    // Pedidos
    'delivery_area_km', 'min_order_amount', 'payment_methods',
    'payment_method_accounts', 'order_auto_confirm',
    // Notificaciones
    'order_notify_customer_email',
    // WhatsApp (gestionados desde /company/whatsapp pero comparten endpoint)
    'whatsapp_read_receipts', 'bot_welcome_message', 'bot_away_message',
    // Branding del menú público
    'menu_primary_color',
];
```

Sección Regional (todos hardcoded por ahora — la allowlist solo acepta los valores actuales):

| Setting | Tipo | Default | Validación |
|---------|------|---------|------------|
| `timezone` | string | `America/Bogota` | `Rule::in(['America/Bogota'])` |
| `currency` | string | `COP` | `Rule::in(['COP'])` |
| `language` | string | `es` | `Rule::in(['es'])` |

Sección Pedidos:

| Setting | Tipo | Default | Validación |
|---------|------|---------|------------|
| `delivery_area_km` | int | 5 | `min:1, max:100` |
| `min_order_amount` | int | 0 | `min:0` |
| `payment_methods` | array | `['efectivo','transferencia']` | cada item `in:efectivo,transferencia,tarjeta,nequi,daviplata` |
| `payment_method_accounts` | array | `{}` | mapa `{method: accountInfo}` |
| `order_auto_confirm` | bool | `false` | — |

Sección Notificaciones:

| Setting | Tipo | Default |
|---------|------|---------|
| `order_notify_customer_email` | bool | `false` |

Branding del menú público:

| Setting | Tipo | Default | Validación |
|---------|------|---------|------------|
| `menu_primary_color` | string | `#0052FF` | regex `^#[0-9A-Fa-f]{6}$` |

Usado por `GET /api/v1/public/menu/{nit}` para pintar botones/headers.

**Patch parcial transaccional** con invalidación de caché (`company_settings:{nit}`, TTL 3600s configurable vía `COMPANY_SETTINGS_CACHE_TTL`):

```php
DB::transaction(function () use ($companyNit, $changes) {
    foreach ($changes as $key => $value) {
        CompanySetting::updateOrCreate(
            ['company_nit' => $companyNit, 'key' => $key],
            ['value' => $value]
        );
    }
    Cache::forget("company_settings:{$companyNit}");
});
audit('company.settings_updated', $actor, $company, ['changes' => array_keys($changes)]);
```

### `/company/dian` — Facturación electrónica

Todas las rutas con prefijo `/api/v1/dian/`. Las globales de empresa no llevan `branch.access`; las operativas (documents, recipients, print) sí.

| Método | Ruta | Permission |
|--------|------|------------|
| `GET` | `/api/v1/dian/fiscal-profile` | `company.fiscal_profile,read` (se edita desde `/company/settings` → "Información") |
| `PUT` | `/api/v1/dian/fiscal-profile` | `company.fiscal_profile,update` (owner-only por template) |
| `GET` | `/api/v1/dian/resolutions` | `dian.config,read` |
| `POST` | `/api/v1/dian/resolutions` | `dian.config,create` |
| `PUT` | `/api/v1/dian/resolutions/{resolution}` | `dian.config,update` |
| `DELETE` | `/api/v1/dian/resolutions/{resolution}` | `dian.config,delete` |
| `GET` | `/api/v1/dian/provider-config` | `dian.config,read` |
| `PUT` | `/api/v1/dian/provider-config` | `dian.config,update` |
| `GET` | `/api/v1/dian/default-recipient` | `dian.config,read` |
| `PUT` | `/api/v1/dian/default-recipient` | `dian.default_recipient,update` |
| `DELETE` | `/api/v1/dian/default-recipient` | `dian.default_recipient,delete` |
| `GET` | `/api/v1/dian/recipients/lookup` | `dian.recipients,read` (+ `branch.access`) |
| `PUT` | `/api/v1/dian/recipients/{contact}/dian-profile` | `dian.recipients,update` (+ `branch.access`) |
| `GET` | `/api/v1/dian/documents` | `dian.documents,read` (+ `branch.access` + `branch.consolidate`) |
| `POST` | `/api/v1/dian/documents` | `dian.documents,create` |
| `POST` | `/api/v1/dian/documents/{document}/retry` | `dian.documents,update` |
| `POST` | `/api/v1/dian/documents/{document}/credit-note` | `dian.documents,update` |
| `POST` | `/api/v1/dian/documents/{document}/convert-to-fev` | `dian.documents,update` |
| `POST` | `/api/v1/dian/documents/{document}/print` | `dian.print,update` |
| `GET` | `/api/v1/dian/documents/{document}/xml` | `dian.documents,read` |
| `GET` | `/api/v1/dian/documents/{document}/pdf` | `dian.documents,read` |
| `POST` | `/api/v1/webhooks/dian/{provider}` | público (HMAC validado) |

Ver `Facturación-Electrónica-DIAN.md` para detalles del modelo, providers soportados (`facturalo`, `factus`), CUFE, ambientes (`habilitacion` / `produccion`) y notas crédito.

### `/company/whatsapp` — WhatsApp Cloud API

| Método | Ruta | Permission | Notas |
|--------|------|------------|-------|
| `GET` | `/api/v1/whatsapp` | `whatsapp.read,read` | Estado actual de la cuenta |
| `POST` | `/api/v1/whatsapp/embedded-signup-callback` | `whatsapp.connect,create` + OTP | Conectar via Meta SDK |
| `POST` | `/api/v1/whatsapp/naas-request` | `whatsapp.connect,create` + OTP | Solicitar número de flexyflow |
| `DELETE` | `/api/v1/whatsapp/phone` | `whatsapp.swap_phone,delete` + Policy + OTP | Cambiar número (owner-only) |
| `DELETE` | `/api/v1/whatsapp` | `whatsapp.disconnect,delete` + Policy + OTP | Desconectar (owner-only) |
| `POST` | `/api/v1/whatsapp/verification/request` | `whatsapp.read,read` | Solicitar OTP |
| `POST` | `/api/v1/whatsapp/verification/verify` | `whatsapp.read,read` | Verificar OTP |
| `GET` | `/api/v1/whatsapp/verification/reject?token=...` | público | Botón "No fui yo" |
| `GET` | `/api/v1/webhooks/whatsapp` | público | Handshake Meta |
| `POST` | `/api/v1/webhooks/whatsapp` | público (HMAC validado) | Eventos Meta |

Ver `WhatsApp-Bot.md` para flow completo (Embedded Signup, NaaS, OTP, webhook).

---

## Flujos funcionales (paso a paso)

### Editar información de empresa

1. Owner/admin abre `/company/settings` tab "Información".
2. UI llama `GET /api/v1/company` para hidratar el formulario.
3. Al guardar, `PUT /api/v1/company` con FormRequest `UpdateCompanyRequest`. Authorization vía `FeaturePermissionService::hasPermission($this, 'company', 'update')` (100% RBAC, no compara por nombre de rol).
4. Si `commercial_name` cambió, `JwtService::reissueWithUpdatedCompanies` emite token nuevo y `Cookie::queue` setea la cookie.
5. Logo/QR se suben al disco default (`s3` en QA/PDN, `local` en dev) bajo `companies/{logos|qr-codes}`.
6. Audit: `company.updated` con `{changed_fields}`.

### Actualizar preferencias

1. UI carga `GET /api/v1/companies/settings`, pinta secciones.
2. Al guardar una card, `PATCH /api/v1/companies/settings` con solo las keys cambiadas.
3. `CompanySettingsService::ALLOWED_KEYS` filtra; keys no permitidas → 422 `key.invalid`.
4. `DB::transaction` corre `updateOrCreate` por key e invalida `company_settings:{nit}`.
5. Audit: `company.settings_updated` con `{changes: array_keys}`.

### Configurar DIAN (perfil fiscal + resolución)

1. **Perfil fiscal del emisor** (DV, representante legal, CIIU, responsabilidades, municipio, contacto/dirección de facturación): se completa desde `/company/settings` → "Información" (sección "Datos fiscales"), `PUT /api/v1/dian/fiscal-profile` gateado con `company.fiscal_profile` (owner-only por template; roles de sistema bypassean). NO está en `/company/dian`.
2. Owner abre `/company/dian` para el resto: UI llama `GET /api/v1/dian/resolutions`, `/dian/provider-config`, `/dian/default-recipient`.
3. **Resolución**: `POST /api/v1/dian/resolutions` con `prefix`, rango `from`–`to`, `valid_from`/`valid_until`, `technical_key`. El sistema valida no solapamiento con resoluciones activas.
4. **Provider**: `PUT /api/v1/dian/provider-config` selecciona `provider` (whitelist en `DianProviderFactory`), credenciales y `environment` (`habilitacion` para pruebas, `produccion` para PDN).
5. **Default recipient**: `PUT /api/v1/dian/default-recipient` define el adquirente genérico (cliente POS sin RUT).
6. Webhook del provider (`POST /api/v1/webhooks/dian/{provider}`) recibe el ACK DIAN con `provider_track_id` validado en `DianWebhookController::verifySignature`.

### Conectar WhatsApp (Embedded Signup)

1. Owner abre `/company/whatsapp`. UI carga el SDK de Facebook con `config_id` por ambiente (`META_CONFIG_ID_QA=941660645323511`, `META_CONFIG_ID_PDN=2605276259869097`).
2. Owner click "Conectar con Facebook" → flow Meta retorna `code`.
3. Antes de enviar al backend, UI pide OTP por correo (`POST /api/v1/whatsapp/verification/request` con `action='connect'`). Rate limit: 3 códigos / 30 min por (company, action).
4. Owner ingresa código de 6 dígitos. UI llama `POST /api/v1/whatsapp/embedded-signup-callback` con header `X-Whatsapp-Verification-Code`.
5. Backend: valida OTP (consume), hace token exchange con Meta, subscribe webhook, persiste `company_whatsapp_accounts` con `status='active'`, `provisioning_mode='embedded_signup'`, `access_token` cifrado.
6. Audit event: `signup_completed` en `company_whatsapp_account_events`.

### Cambiar número WhatsApp (owner-only)

1. Owner click "Cambiar número" → UI pide OTP (`action='swap_phone'`).
2. `DELETE /api/v1/whatsapp/phone` con header OTP.
3. Backend: `Gate::authorize('swapPhone', $account)` (Policy compara por nombre de rol owner). Consume OTP.
4. Llama Meta para deregistrar el `phone_number_id`. Setea `status='disconnected'`, nulifica `phone_number_id` y `phone_e164`.
5. Event `swap_phone` en `company_whatsapp_account_events`.
6. Owner debe hacer Embedded Signup nuevo con el número nuevo.

### Desconectar WhatsApp (owner-only)

1. Owner click "Desconectar" → UI pide OTP (`action='disconnect'`).
2. `DELETE /api/v1/whatsapp` con header OTP.
3. Backend: `Gate::authorize('disconnect', $account)`, consume OTP, soft-delete (`deleted_at=now()`).
4. `chats` y `chat_messages` históricos quedan (compliance).

---

## Componentes frontend

| Archivo | Propósito |
|---------|-----------|
| `pages/company/settings.tsx` | Tabs Información + Facturación + Impuestos |
| `pages/company/preferences.tsx` | Cards Regional / Pedidos / Notificaciones / Branding |
| `pages/company/dian.tsx` | Wizard de configuración fiscal + resoluciones + provider + default recipient (~876 líneas) |
| `pages/company/whatsapp.tsx` | Embedded Signup + estado + preferencias bot |
| `pages/company/kds.tsx` | Estaciones KDS por sede + tokens de tablet (ver `Cocina.md`) |
| `pages/company/printers.tsx` | Impresoras térmicas |
| `pages/company/branches/*` | CRUD de sedes |
| `pages/company/under-review.tsx` | Pantalla bloqueante #154 (ver `Onboarding.md`) |
| `components/billing/subscription-card.tsx` | Card del plan actual en tab Facturación |
| `components/billing/overdue-banner.tsx` | Banner de mora |
| `components/dian/*` | Formularios y selectores DIAN |
| `components/whatsapp/*` | Estado + OTP modal + cards preferencias |
| `hooks/use-bootstrap.ts` | Hidrata `legalUrls`, `tax_presets`, `availableBanks` |

Tokens del DS obligatorios: `bg-card`, `text-muted-foreground`, `border-border`, `var(--color-status-*)`. Cero hex hardcoded en paletas semánticas (la identidad de marca del menú público es la excepción explícita, `style={{...}}`).

---

## Eventos de auditoría

| Acción | Disparador | Data |
|--------|-----------|------|
| `company.updated` | `CompanyController::update` | `{changed_fields, logo_changed, qr_changed}` |
| `company.settings_updated` | `CompanySettingsController::update` | `{changes: [keys]}` |
| `company.status_changed_external` | Workflow GH (#154) | `{from, to, reason, workflow_run_url}` |
| `dian.fiscal_profile.updated` | `FiscalProfileController::update` | `{changed_fields}` |
| `dian.resolution.created` / `updated` / `deleted` | `DianResolutionController` | `{resolution_id, prefix, range}` |
| `dian.provider_config.updated` | `DianProviderConfigController::update` | `{provider, environment}` |
| `dian.default_recipient.updated` / `deleted` | `DianDefaultRecipientController` | `{recipient_id}` |
| `dian.document.created` / `retried` / `credit_note` / `convert_to_fev` / `print` | `ElectronicDocumentController` | `{document_id, type, amount, cufe}` |
| `whatsapp.signup_completed` | `WhatsappAccountController::embeddedSignupCallback` | `{waba_id, phone_number_id}` |
| `whatsapp.swap_phone` | `WhatsappAccountController::deletePhone` | `{old_phone_number_id}` |
| `whatsapp.disconnected` | `WhatsappAccountController::destroy` | `{phone_number_id}` |
| `whatsapp.verification.sent` / `consumed` / `rejected` | `WhatsappVerificationController` | `{action, attempts}` |

Catálogo canónico en `application/constants/AUDIT_EVENTS.md`.

---

## Edge cases y empty states

- **Empresa en `pending_activation`**: `/company/settings`, `/company/preferences`, `/company/dian`, `/company/whatsapp` redirigen a `/company/under-review` por `EnsureCompanyVerified`. Solo `GET /api/v1/companies/active` queda accesible para pintar el estado.
- **Empresa `suspended` (mora prolongada)**: `EnsureCompanyNotBlocked` redirige a billing. Billing y comprobantes (`/billing/invoices`) siguen accesibles.
- **Llave no permitida en `PATCH /companies/settings`**: 422 con `key.invalid` y detalle de qué key falló. El frontend solo expone keys de la allowlist, pero el backend protege ante manipulación.
- **Cache de settings sucio**: el `Cache::forget` corre dentro de `DB::transaction` para que el siguiente `GET` no devuelva valores viejos. En N-instance (Redis), el invalidate es global.
- **Logo o QR > 5 MB**: 422 con `validation.{campo}.max`. El frontend valida antes de subir.
- **`phone_number_id` ya en uso**: el UNIQUE parcial (WHERE `deleted_at IS NULL`) permite reusar tras soft-delete del anterior.
- **OTP WhatsApp expirado o agotado**: `expires_at < now()` o `attempts >= 3` → 422 con `expired_or_not_found` / `too_many_attempts`. Owner solicita uno nuevo.
- **Reject token usado en email**: `WhatsappVerificationController::reject` marca `rejected_at=now()`. El código queda inválido y se notifica al owner por correo.
- **Resolución DIAN expirada**: `dian_resolutions.valid_until < now()` bloquea emisión nueva. UI muestra warning en `/company/dian` y CTA "Solicitar nueva resolución".
- **Provider DIAN sin credenciales**: `POST /dian/documents` falla con 500/502 desde el provider. Frontend muestra "Configura el proveedor antes de facturar".
- **Sin plantilla aprobada para mensaje fuera de sesión** (WhatsApp 24h): el bot solo puede responder mensajes "in-session" — fuera de la ventana de 24h Meta exige plantillas aprobadas. Actualmente fuera de alcance.

---

## Cross-references

- Constants: `application/constants/PERMISSIONS_CATALOG.md`, `BRANCH_RBAC.md`, `ACCOUNTING_RULES.md`, `MIDDLEWARE_MAP.md`, `AUDIT_EVENTS.md`, `FEATURES_INDEX.md`.
- Backend: `app/Http/Controllers/Api/{CompanyController,CompanySettingsController,WhatsappAccountController,WhatsappVerificationController,WhatsappWebhookController}.php`, `app/Http/Controllers/Api/Dian/*.php`, `app/Services/{CompanySettingsService,Whatsapp\*}.php`, `app/Policies/WhatsappAccountPolicy.php`, `app/Http/Middleware/{EnsureCompanyAccess,EnsureCompanyVerified,EnsureCompanyNotBlocked}.php`.
- Frontend: `src/pages/company/{settings,preferences,dian,whatsapp,kds,printers,under-review}.tsx`, `src/pages/company/branches/`, `src/hooks/use-bootstrap.ts`, `src/components/billing/*`, `src/components/dian/*`, `src/components/whatsapp/*`.
- Wiki relacionado: `Empresas.md`, `Onboarding.md`, `Facturación-Electrónica-DIAN.md`, `WhatsApp-Bot.md`, `Cocina.md`, `Multi-tenancy.md`, `Usuarios-Roles-Permisos.md`.
