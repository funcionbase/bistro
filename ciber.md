# Auditoría de ciberseguridad — backend + frontend (bistro)

> Analista de seguridad · 2026-07-01 · empresa QA **QA Test Restaurante** (NIT 901555444-1).
> Cubre 10 hallazgos: CIBER-01..03 (BOLA/IDOR/XSS) + CIBER-04..10 (media chat, storage-proxy, CSRF, JWT, OAuth, CORS).
> Checklist para ir marcando a medida que se resuelva **en la siguiente pasada** (esta solo documenta).
> Escenario de explotación de cada ítem → `plan.md` §11. NO se abrieron issues (por pedido del usuario).
>
> Severidad: 🔴 alta · 🟠 media · 🟡 baja/hardening. Marcá `[x]` al cerrar + nota de commit.

---

## Hallazgos abiertos

- [x] **🔴 CIBER-01 — Fuga de PII cross-tenant + BOLA en asignación de domiciliario** — ✅ FIX: `user_id` scopeado a `company_users`.`company_nit` activo en las 3 FormRequests (Store/Assign/Reassign); `cedula`+`google_id` agregados a `User::$hidden` (defense-in-depth).
  - **Dónde:**
    - `bistro/backend/app/Http/Requests/Delivery/AssignCourierRequest.php:33`
    - `bistro/backend/app/Http/Requests/Delivery/StoreDeliveryRequest.php:36`
    - `bistro/backend/app/Http/Requests/Delivery/ReassignDeliveryRequest.php:35`
    - Consumido en `app/Http/Controllers/Api/DeliveryController.php:104` (`assignCourier`), `:133` (`store`), y el reassign; el service `app/Services/DeliveryService.php:82` (`assignDeliverer`) / `:449` (`assertCourierCapacity`) **NO** valida que el `user_id` sea miembro de la empresa (solo cuenta capacidad).
  - **Causa:** la regla es `['required','uuid','exists:users,id']` — `exists` global, sin `company_nit`. Cualquier UUID de usuario del sistema pasa.
  - **Impacto:** un actor con `deliveries.create`/`deliveries.update` asigna como courier a **cualquier usuario de cualquier empresa** con solo su UUID. La respuesta hace `$delivery->load(['deliverer'])` y el modelo `User` solo tiene `$hidden = ['password','remember_token']` → el JSON `data.deliverer` devuelve **email, cédula, google_id, nombre completo** del usuario ajeno. Fuga de PII (incluida cédula/documento) cross-tenant + contaminación de datos (delivery apuntando a un user foráneo).
  - **Fix sugerido (siguiente pasada):** validar pertenencia — `Rule::exists('company_users','user_id')->where('company_nit', $activeNit)` en las 3 FormRequests, o resolver el `$deliverer` con `->whereHas('companyUsers', fn($q)=>$q->where('company_nit',$nit))` y 404 si no. Además exponer el courier vía un Resource acotado (id + nombre), no el modelo `User` crudo.

- [x] **🟠 CIBER-02 — Stored XSS vía SVG en el logo de empresa (política inconsistente)** — ✅ FIX: `svg` retirado de `mimes` del logo en `UpdateCompanyRequest` (solo jpg/png/webp); docblock actualizado.
  - **Dónde:** `bistro/backend/app/Http/Requests/Company/UpdateCompanyRequest.php:58` → `'logo' => [...,'mimes:jpg,jpeg,png,svg,webp',...]`. Se persiste a disco público en `app/Http/Controllers/Company/CompanyController.php:94`.
  - **Contraste:** `app/Http/Requests/Menu/UploadDishImageRequest.php:13-14` **rechaza SVG a propósito** documentando que "svg permite [scripts]". El logo abre justo el vector que las imágenes de plato cierran.
  - **Causa:** `mimes:svg` acepta un SVG **válido** que contenga `<script>`/`onload`. El comentario del código dice que `mimes` evita "colar renombrado" — cierto, pero no cubre un SVG legítimo malicioso.
  - **Impacto:** owner/admin (o rol con `company.update`) sube un SVG con JS. Si el disco público es same-origin y el archivo se abre por URL directa (o se embebe con `<object>`/`<iframe>`), el script corre en el origin de la app. El logo se renderiza además en el menú público de la empresa (multi-visitante).
  - **Fix sugerido:** quitar `svg` de `mimes` del logo (alinear con dish image), o sanitizar el SVG en persistencia (whitelist de tags/attrs) y servir con `Content-Type: image/svg+xml` + `Content-Disposition: attachment` + CSP `sandbox`.

## Hardening / defense-in-depth (menor)

- [x] **🟡 CIBER-03 — Resolver modelo por UUID global antes del check de pertenencia** — ✅ FIX: `TableOrderController::updateItem/cancelItem` scopean `OrderItem` por `guest_id = $guest->id` en la query (404 estructural).
  - **Dónde:** `app/Http/Controllers/Public/TableOrderController.php:77` y `:109` — `OrderItem::query()->whereKey($itemId)->firstOrFail()` sin scope; la pertenencia la valida después `TableOrderService::updateItem/cancelItem` con `if ($locked->guest_id !== $guest->id)` (`app/Services/TableOrderService.php:132`,`:207`).
  - **Estado:** **hoy NO explotable** (el check de `guest_id` corta el cross-guest). Se documenta porque es el **mismo patrón** que causó los 2 IDOR ya cerrados en #295 (`CancellationRequestController`, `TableSessionController`). Fragilidad: si un refactor mueve el uso del item antes del guard, se abre el IDOR.
  - **Fix sugerido:** scopear en la query (`->whereHas('order', fn($q)=>$q->where('table_session_id', $guest->session_id))`) para que sea 404 estructural, no dependiente del service.

---

## Hallazgos abiertos — pasada 2 (auth/sesión/almacenamiento)

- [x] **🔴 CIBER-04 — `sprintf('%d', <uuid>)` en rutas de media de chat → colisión cross-cliente + enumeración trivial** — ✅ FIX: `DownloadWhatsappMediaJob` usa `%s` con UUID completos; migración `2026_07_01_180000` anula los `media_path` colapsados viejos (dejan de servir media ajena).
  - **Dónde:** `bistro/backend/app/Jobs/DownloadWhatsappMediaJob.php:104` → `sprintf('chat-media/%d/%d.%s', $chat->id, $message->id, $extension)`. `Chat` y `ChatMessage` usan `HasUuids` (IDs UUID string), pero `%d` **castea el UUID a entero** = solo el run de dígitos decimales iniciales (la mayoría → `0` o 1 dígito). Se persiste como `media_path` (`:109`) y se sirve vía `SignedAssetUrl::for` → `/storage-proxy/...` (`app/Support/SignedAssetUrl.php:42`).
  - **Impacto (doble):**
    1. **Privacidad/integridad:** todas las media cuyos UUID colapsan al mismo dígito comparten path (`chat-media/9/9.jpg`, `chat-media/0/0.jpg`…). `Storage::put` (`:106`) **sobrescribe**: el último archivo escrito es el que sirve para todos los mensajes colisionados → un cliente/empresa termina viendo/descargando la foto/PDF/nota de voz de **otro** cliente de otra empresa.
    2. **Enumeración:** el keyspace efectivo es ~`chat-media/{0-9}/{0-9}.{jpg,png,pdf,ogg,...}` — decenas de combinaciones. Vía el proxy sin auth (ver CIBER-05) se baja toda la media de chats existente en pocas requests.
  - **Fix sugerido:** usar `%s` con los UUID completos (`chat-media/{chat-uuid}/{message-uuid}.ext`); migrar los `media_path` ya escritos. Idealmente añadir un componente aleatorio (`Str::random`) para que la clave no sea derivable del ID.

- [x] **🟠 CIBER-05 — `storage-proxy` firma objetos sin autenticación ni scope de tenant** — ✅ FIX: `chat-media/` retirado del proxy anónimo; nuevo endpoint `GET /api/v1/chats/{id}/messages/{messageId}/media` (JWT + company.access + `chats.read`, scope de empresa) firma la URL (302, TTL 15min). `ChatMessageResource` apunta ahí. Frontend sin cambios (`<img/video/audio src>`).
  - **Dónde:** `bistro/backend/routes/web.php:64` (`storage-proxy/{path}` where `.*`, solo `throttle:120,1`) → `app/Http/Controllers/StorageProxyController.php:55`. Bloquea `..` y restringe a prefijos `companies/`, `menus/`, `chat-media/` (`:36`), pero **no exige JWT ni valida que el solicitante pertenezca a la empresa dueña** del objeto.
  - **Impacto:** cualquiera (sin login) que conozca/adivine una clave bajo `chat-media/` obtiene una URL S3 firmada a media **privada** de conversaciones de clientes, cross-tenant. `companies/`+`menus/` son semipúblicos (logo/QR/fotos del menú) y ahí es aceptable; `chat-media/` NO debería servirse por este canal anónimo. Agravado por CIBER-04 (claves triviales).
  - **Fix sugerido:** separar `chat-media/` a un endpoint autenticado que resuelva el `ChatMessage`, valide `chats.read` + pertenencia a la empresa/sede, y recién ahí firme la URL. Mantener el proxy anónimo solo para assets genuinamente públicos.

- [x] **🟠 CIBER-06 — CSRF en la API: cookie `SameSite=None` + sin token CSRF + sin chequeo de Origin** — ✅ FIX: middleware `EnsureTrustedOrigin` (api prepend) exige `Origin`/`Referer` del allowlist CORS en métodos no idempotentes **cuando viaja la cookie JWT**; Bearer/webhooks (sin cookie) intactos.
  - **Dónde:** cookie JWT `SameSite=None`/`Secure` en deploy cross-origin (`app/Services/JwtService.php:119` lee `config('session.same_site')`; `.env.example:109` `SESSION_SAME_SITE=none`). `ValidateJwt` (`app/Http/Middleware/ValidateJwt.php`) autentica por cookie/bearer/query **sin validar Origin/Referer**; las rutas `api/*` no montan `VerifyCsrfToken` (es solo del grupo `web`).
  - **Impacto:** una página atacante puede disparar peticiones autenticadas con la cookie adjunta (SameSite=None) usando content-types "simples" (`multipart/form-data`, `application/x-www-form-urlencoded`, `text/plain`) que **no** disparan preflight CORS, y con method-spoofing `_method=DELETE|PATCH|PUT`. El atacante no lee la respuesta (CORS la bloquea) pero la **acción de estado ya se ejecutó**: cancelar/reembolsar órdenes, borrar recursos, subir logo (multipart), etc.
  - **Fix sugerido:** middleware de CSRF para `api/*` con `SameSite=None`: verificar `Origin`/`Referer` contra el allowlist (mismo patrón que CORS) en métodos no-idempotentes, o exigir un header custom (`X-Requested-With`/doble-submit token) que fuerce preflight y sea bloqueado por CORS a orígenes ajenos.

- [x] **🟡 CIBER-07 — JWT aceptado por query string `?token=`** — ✅ FIX: `JwtService::extractTokenFromRequest` ya no lee `?token=` (solo cookie > Bearer > session flash). OAuth entrega el JWT por cookie en el redirect, no por query.
  - **Dónde:** `bistro/backend/app/Services/JwtService.php:96-99` (`extractTokenFromRequest` acepta `?token=`), usado por `ValidateJwt`. Varias rutas web preservan `?token=` en redirects (`web.php:157`,`:174`).
  - **Impacto:** el JWT (credencial de sesión completa) viaja en la URL → queda en logs de acceso (nginx/ALB/CloudWatch), historial del navegador, header `Referer` hacia terceros y caches de proxy. Un token filtrado por logs = secuestro de sesión hasta `exp`.
  - **Fix sugerido:** retirar la aceptación por query param (ya migraron el front a cookie HttpOnly; el frontend memory confirma que se quitaron las escrituras de `?token=`). Si se necesita para un handoff puntual, hacerlo one-time y canjearlo de inmediato por cookie.

- [x] **🟡 CIBER-08 — OAuth: linking/adopción de cuenta por email sin verificar `email_verified`** — ✅ FIX: match primario por `google_id`; linking por email solo si `email_verified` de Google; no adopta cuentas ya vinculadas a otro `google_id` (403 con log); rechaza login sin email verificado.
  - **Dónde:** `bistro/backend/app/Http/Controllers/Auth/GoogleAuthController.php:72-74` → `User::where('google_id', ...)->orWhere('email', $googleUser->getEmail())->first()`, y `:91` `update(['google_id' => ...])`. No se chequea el claim `email_verified` del payload de Google ni se separa el flujo de "vincular".
  - **Impacto:** un login de Google cuyo email coincide con una cuenta existente **la adopta** y reescribe su `google_id`. Para emails Gmail el riesgo es bajo (Google los verifica), pero para emails de dominio propio (invitaciones a `alguien@empresa.com`) alguien que controle Google Workspace de ese dominio podría mintear la cuenta y tomar el acceso. `enforce_gsuite_domain` está off por default.
  - **Fix sugerido:** matchear solo por `google_id`; para vincular por email exigir `getRaw()['email_verified'] === true` y, si la cuenta ya existía sin ese `google_id`, un paso de confirmación explícito.

- [x] **🟡 CIBER-09 — CORS: wildcard de subdominios `*.flexyflow.co` con credenciales** — ✅ FIX: los patrones ahora salen de `CORS_ALLOWED_ORIGINS_PATTERNS` (default = wildcard actual, load-bearing para subdominios del carrito). pdn puede fijar orígenes explícitos y vaciar el patrón sin tocar código. Compone con CIBER-02 (SVG XSS ya cerrado) y CIBER-06 (mismo allowlist).
  - **Dónde:** `bistro/backend/config/cors.php:59` `allowed_origins_patterns => ['#^https://([a-z0-9-]+\.)*flexyflow\.co$#i']` + `supports_credentials => true` (`:67`).
  - **Estado:** el patrón está **bien anclado** (no lo evaden `evilflexyflow.co` ni `flexyflow.co.evil.com`). Pero cualquier subdominio de `flexyflow.co` es origen CORS confiable **con credenciales**. Si un subdominio tiene XSS (p.ej. el menú público con el SVG de CIBER-02) o sufre subdomain takeover, puede hacer requests credenciadas a la API.
  - **Fix sugerido:** preferir allowlist explícita de los 2-3 orígenes reales del SPA en vez del wildcard; monitorear registros DNS para takeover. Bajo, pero compone con CIBER-02/CIBER-06.

- [x] **🟡 CIBER-10 — OAuth `stateless()` sin validación de parámetro `state` (login CSRF)** — ✅ FIX: `state` propio (`Str::random(40)`) en cookie HttpOnly de 10min (double-submit, N-instance safe); el callback lo valida con `hash_equals` contra el `?state=` echo de Google antes de resolver el usuario.
  - **Dónde:** `GoogleAuthController.php:49`,`:55` (`Socialite::driver('google')->stateless()`).
  - **Impacto:** sin binding de `state` a la sesión del iniciador, un atacante puede completar el flujo para forzar al victim a loguearse en una cuenta controlada por el atacante (login CSRF). Impacto acotado porque no hay sesión previa de valor, pero permite trampas de "operás dentro de la cuenta del atacante".
  - **Fix sugerido:** usar el flujo con `state` (no-stateless) apoyado en un store compartido (cache/cookie) si la sesión server-side no es viable cross-domain, o un `state` propio firmado y validado en el callback.

---

## Verificado limpio en esta pasada (para no re-auditar)

**Pasada 2:** firma JWT con `hash_equals` (timing-safe) y **alg fijo HS256** — el header del token no elige algoritmo, no hay confusión `alg:none`/RS→HS · payload AES-256-CBC **autenticado por el HMAC externo** (encrypt-then-MAC a nivel token) → sin padding oracle · tope de vida absoluto (`auth_time + max_lifetime`) + blacklist (`JWT_BLACKLIST_ENABLED=true` en `.env.example` y local) → logout/revocación efectivos · `switch-company/branch` ya validados (pasada 1) · `bot.jwt` solo por bearer (sin query) · `StorageProxyController` bloquea `..` y restringe prefijos (el hueco es la falta de auth en `chat-media/`, no traversal) · tokens/secretos con CSPRNG.

**Pasada 1 (sigue vigente):**

- **SQLi:** todos los `whereRaw`/`selectRaw`/`DB::raw` revisados usan binding o expresiones sin input de usuario. `WorkforceReportController` interpola `$hoursExpr` pero sale del **driver** (pgsql|sqlite), no del request. Búsquedas usan `ESCAPE '!'` con placeholders.
- **Tenant switch:** `AuthController::switchCompany` (`:101`) y `switchBranch` (`:199`) validan membresía (`company_users` activo / `canAccessBranch`) antes de reemitir JWT. No se puede saltar de empresa por body.
- **KDS:** transiciones de item (JWT `:389` y device-token `:430`) scopean por `company_nit` + `branch_id` (+ estación en device). Sin IDOR.
- **Loyalty:** `adjust`/`redeem` (`LoyaltyController:121`,`:145`) toman `company_nit` de `active_company_nit` (JWT), no del path; cuenta scopeada por empresa.
- **Mass assignment:** `User::$fillable` no incluye `role`/`company_nit`/`is_system`. `UpdateCompanyRequest` no permite `status`/`nit`/`plan` (no hay auto-activación). `CompanyController::update` opera sobre `$validated` acotado.
- **Uploads (no-SVG):** dish/proof/purchase/branch-banner validan `mimes` + `max`. Tokens y secretos usan CSPRNG (`random_bytes`/`Str::random`).
- **Webhooks públicos:** WhatsApp (HMAC), SES (firma X.509 SNS), DIAN (HMAC + throttle) verifican firma; no confían en el body para tenant.
