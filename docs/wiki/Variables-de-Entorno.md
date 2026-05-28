# Variables de Entorno

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

Lista de variables `.env` reconocidas por la aplicación, agrupadas por dominio. Las marcadas como **Obligatoria** deben estar definidas en cualquier entorno; las **Opcionales** tienen valor por defecto y solo se sobrescriben para casos especiales.

> Fuente canónica: `application/backend/.env.example`. El workflow
> `.github/workflows/sync-env-secret.yml` copia ese archivo literal a qa/pdn
> y sobreescribe SOLO un subset corto desde GH Environments (#174 cleanup).
> Si una variable nueva tiene que entrar a qa/pdn pero NO debe ir en el
> commit (secreto), agregala como variable del GH Environment y suma su
> nombre al subset del workflow.

---

## Aplicación

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `APP_NAME` | Sí | `Laravel` | Nombre mostrado en `<title>` y emails |
| `APP_ENV` | Sí | `production` | `local` \| `staging` \| `production` |
| `APP_KEY` | Sí | — | Clave de cifrado Laravel; generar con `php artisan key:generate` |
| `APP_DEBUG` | Sí | `false` | `true` solo en local |
| `APP_URL` | Sí | `http://localhost` | URL canónica usada en correos y links |
| `APP_TIMEZONE` | No | `America/Bogota` | Zona horaria por defecto |
| `APP_LOCALE` | No | `es` | Locale principal de la interfaz |
| `APP_FALLBACK_LOCALE` | No | `es` | Locale de fallback |
| `APP_FAKER_LOCALE` | No | `es_ES` | Locale de Faker para seeders/factories |
| `APP_MAINTENANCE_DRIVER` | No | `file` | Driver de modo mantenimiento (`file` \| `cache`) |
| `PHP_CLI_SERVER_WORKERS` | No | `1` | Workers del servidor PHP CLI integrado |
| `BCRYPT_ROUNDS` | No | `12` | Costo bcrypt de hashing de contraseñas |
| `FRONTEND_URL` | No | (según `APP_ENV`) | URL del SPA para el redirect post-OAuth y los orígenes CORS. `config/app.php` la resuelve por `APP_ENV`: `pdn`/`production`→`https://panel.flexyflow.co`, `qa`→`https://panel-qa.flexyflow.co`, local→`http://localhost:5173`. Definila **solo** como override puntual; en `.env.example` va comentada — si se filtra un valor de dev, el callback de Google redirige a `localhost` |

---

## Logs

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `LOG_CHANNEL` | No | `stack` | `stack` \| `single` \| `daily` \| `slack` \| `papertrail` \| `syslog` \| `errorlog` |
| `LOG_STACK` | No | `single` | Sub-canal cuando `LOG_CHANNEL=stack` |
| `LOG_DEPRECATIONS_CHANNEL` | No | `null` | Canal para logs de deprecation |
| `LOG_LEVEL` | No | `debug` | Nivel mínimo a registrar |

---

## Base de datos

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `DB_CONNECTION` | Sí | `pgsql` | Recomendado en prod (`pgsql`); el dev también usa Postgres |
| `DB_HOST` | Sí | `127.0.0.1` | |
| `DB_PORT` | Sí | `5432` | |
| `DB_DATABASE` | Sí | — | Nombre de la base |
| `DB_USERNAME` | Sí | — | |
| `DB_PASSWORD` | Sí | — | |

---

## JWT (sesiones de usuario)

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `JWT_SECRET` | Sí | — | Clave HMAC-SHA256 para firmar tokens |
| `JWT_PAYLOAD_ENCRYPTION_KEY` | Sí | — | Clave base para derivar AES-256 que cifra el payload |
| `JWT_TTL` | No | `3600` | TTL en segundos |
| `JWT_BLACKLIST_ENABLED` | No | `false` | Activa la lista negra en cache |

---

## JWT del bot

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `BOT_JWT_SECRET` | Solo si hay bot | — | Clave HMAC HS256 para JWT del bot externo. Generar con `php -r "echo bin2hex(random_bytes(48));"` |
| `BOT_JWT_TTL` | No | `3600` | TTL en segundos del JWT de bot |

---

## JWT del carrito (pedidos.flexyflow.co)

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `CART_JWT_SECRET` | Sí en pdn | — | Clave HMAC HS256 para `CartJwtService` |
| `CART_JWT_TTL` | No | `4200` | TTL en segundos del JWT de carrito (70 min) |
| `CART_BASE_URL` | No | `https://pedidos.flexyflow.co` | URL base del front de carrito (se concatena `{jwt}`) |

---

## Sesión y cookies

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `SESSION_DRIVER` | No | `database` | `database` \| `file` \| `cookie` |
| `SESSION_LIFETIME` | No | `120` | Minutos de vida de la sesión |
| `SESSION_SECURE_COOKIE` | No | `false` | `true` en qa/pdn (HTTPS). Obligatorio `true` si `SESSION_SAME_SITE=none` |
| `SESSION_SAME_SITE` | No | `none` | Política SameSite de las cookies (`flexyflow_jwt`, sesión, mesa). `none` es obligatorio en el deploy cross-origin — el SPA (`panel.flexyflow.co`) y la API (`panel-api.flexyflow.co`) viven en hosts distintos. En local sobre HTTP, `JwtService::buildCookie` degrada `none`→`lax` automáticamente y el dev no se rompe |
| `SESSION_DOMAIN` | No | `null` | Dominio de la cookie. Vacío/`null` para que el navegador la asocie al host exacto |

> **Cross-origin:** `SameSite=None` exige `Secure` — el navegador descarta una
> cookie `none` sin `secure`. En qa/pdn van siempre juntas:
> `SESSION_SAME_SITE=none` + `SESSION_SECURE_COOKIE=true`.

---

## Seguridad HTTP

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `SECURITY_HEADERS_ENABLED` | No | `true` | Habilita el middleware `SecurityHeaders` (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy). Sin esto la app pierde defensas a nivel de respuesta. |
| `CSP_ENABLED` | No | `false` | Habilita Content-Security-Policy con nonce por request. Off por default — Tailwind v4 inyecta estilos inline en runtime. Rollout (#200): primero `true` en qa via GH Env vars + 7 días de monitoreo, luego flip en pdn. |
| `CSP_REPORT_URI` | No | `/api/v1/csp-report` | Endpoint donde el browser reporta violaciones CSP. Se persisten en `audit_logs` con `action='csp.violation'`. |
| `HSTS_ENABLED` | No | `false` | Habilita `Strict-Transport-Security`. Requiere TLS válido en dominio + subdominios. QA/PDN lo activan via GH Env vars (#174 P3-3). |

---

## Google OAuth

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `GOOGLE_CLIENT_ID` | Sí | — | OAuth Client ID |
| `GOOGLE_CLIENT_SECRET` | Sí | — | OAuth Client Secret |
| `GOOGLE_REDIRECT_URI` | Sí | `http://localhost/auth/google/callback` | Debe coincidir con la callback registrada en Google Cloud Console |
| `ENFORCE_GSUITE_DOMAIN` | No | `false` | Si es un dominio (ej. `empresa.com`), solo se aceptan cuentas Google Workspace de ese dominio |
| `OAUTH_RATE_LIMIT` | No | `10` | Límite de intentos OAuth por minuto por IP |

---

## Correo electrónico (Amazon SES + Cloudflare Routing)

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `MAIL_MAILER` | Sí | `log` | `log` en local; `ses` en qa/pdn (override via `sync-env-secret.yml`) |
| `MAIL_FROM_ADDRESS` | Sí | `noreply@flexyflow.co` | Debe pertenecer al dominio verificado en SES |
| `MAIL_FROM_NAME` | Sí | `${APP_NAME}` | Nombre mostrado al destinatario |
| `MAIL_REPLY_TO_ADDRESS` | No | `soporte@flexyflow.co` | Buzón que recibe respuestas vía CF Email Routing |
| `MAIL_REPLY_TO_NAME` | No | `${MAIL_FROM_NAME}` | Nombre del reply-to (hereda del from) |
| `SES_CONFIGURATION_SET` | No | — | Configuration Set de SES (Fase 2 — habilita SNS para bounces) |
| `SES_WEBHOOK_SECRET` | No | — | Secreto compartido para validar webhook SNS (Fase 2) |
| `AWS_DEFAULT_REGION` | Sí | `us-east-1` | Región AWS — debe coincidir con la región de los identities de SES |

**Importante:** las credenciales AWS (`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`) **no se setean en qa/pdn** — el SDK las toma del IAM instance profile del ASG. Mismo patrón que S3.

Ver [`EMAIL_SES_SETUP.md`](EMAIL_SES_SETUP.md) para configuración completa de DKIM, Custom MAIL FROM, SPF, DMARC, IAM policy, salida de sandbox y Cloudflare Email Routing.

---

## Negocio — Empresa

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `DEFAULT_COMPANY_STATUS` | No | `pending_activation` | Estado inicial al crear empresa (`pending_activation` \| `active`) |
| `AVAILABLE_BANKS` | No | `Bancolombia,Davivienda,BBVA,Nequi` | Bancos disponibles en métodos de pago (lista separada por coma) |

---

## Documentos legales (wiki externo)

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `LEGAL_WIKI_BASE_URL` | No | `https://flexyflow.co` | URL base del wiki externo donde viven TOS / privacidad / contrato. En local: `http://localhost:4321`. La consume `config/legal.php` y la expone `BootstrapService::buildCatalogs()` al SPA. |

---

## Facturación de plataforma

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `BILLING_CURRENCY` | No | `COP` | Moneda (ISO 4217) |
| `BILLING_GRACE_MONTHS` | No | `2` | Meses de gracia antes de suspender |
| `BILLING_DUE_DAY` | No | `15` | Día del mes de vencimiento |
| `BILLING_GENERATE_DAY` | No | `20` | Día del mes en que se generan facturas |
| `BILLING_GENERATE_HOUR` | No | `3` | Hora UTC del cron de generación |
| `BILLING_OVERDUE_DAY` | No | `16` | Día en que se marcan facturas vencidas |
| `BILLING_OVERDUE_HOUR` | No | `3` | Hora UTC del cron de mora |
| `INVOICE_PDF_DRIVER` | No | `dompdf` | Motor PDF para facturas |
| `INVOICE_STORAGE_DISK` | No | `s3_documents` | Disco para PDFs de facturas (DIAN 10 años → bucket privado) |
| `BILLING_NOTIFY_ON_GENERATE` | No | `true` | Notifica al owner/admin cuando se genera factura |
| `BILLING_NOTIFY_ON_OVERDUE` | No | `true` | Notifica al owner/admin cuando una factura pasa a vencida |
| `BILLING_DOWNLOAD_TTL` | No | `3600` | TTL en segundos de la URL firmada para descargar el PDF |
| `BILLING_PAST_DUE_GRACE_MONTHS` | No | `3` | Meses calendario que la empresa puede operar `past_due` antes de pasar a `suspended` (#175) |
| `BILLING_TRIAL_DAYS` | No | `90` | Días de prueba post-creación durante los cuales la empresa se mantiene `active` aunque no tenga invoices (#175) |
| `BILLING_PAYMENT_PROOF_DISK` | No | `s3_documents` | Disco para comprobantes de pago subidos por el cliente (#175) |
| `BILLING_DELINQUENT_EXPORT_DISK` | No | `s3_documents` | Disco para el CSV diario de morosos (uso interno) (#175) |
| `BILLING_DELINQUENT_EXPORT_PREFIX` | No | `flexyflow-internal/delinquent-companies` | Prefijo del CSV de morosos en el bucket |
| `BILLING_OPS_EMAIL` | No | `ops@flexyflow.co` | Email operativo para notificación de comprobantes subidos (#175) |

### Datos para transferir a flexyflow (#246)

Visibles al cliente en `/company/settings → Facturación` y en `SuspendedBlockedView`. No son secretos.

| Variable | Default | Descripción |
|----------|---------|-------------|
| `FLEXYFLOW_PAYMENT_BREB_KEY` | `@***REMOVED-BREB-KEY***` | Llave Bre-B para transferencias instantáneas |
| `FLEXYFLOW_PAYMENT_BANK_NAME` | `Bancolombia` | Banco destinatario |
| `FLEXYFLOW_PAYMENT_ACCOUNT_NUMBER` | `***REMOVED-ACCOUNT-NUMBER***` | Número de cuenta |
| `FLEXYFLOW_PAYMENT_ACCOUNT_TYPE` | `ahorros` | Tipo de cuenta |
| `FLEXYFLOW_PAYMENT_ACCOUNT_HOLDER` | `CRISTIAN MARIN` | Titular |
| `FLEXYFLOW_NIT` | `900000001` | NIT de flexyflow (diligenciamiento de transferencia) |
| `FLEXYFLOW_DV` | `1` | Dígito de verificación |
| `FLEXYFLOW_COMMERCIAL_NAME` | `flexyflow SAS` | Nombre comercial |
| `FLEXYFLOW_LEGAL_NAME` | `flexyflow SAS` | Razón social |
| `FLEXYFLOW_ADDRESS` | `Cartago, Valle del Cauca` | Dirección fiscal |
| `FLEXYFLOW_MUNICIPALITY_DANE` | `76147` | Código DANE municipal |
| `FLEXYFLOW_BILLING_EMAIL` | `facturacion@flexyflow.co` | Email de facturación |
| `FLEXYFLOW_BILLING_PHONE` | `+***REMOVED-PHONE***` | Teléfono de facturación |

---

## Exportación PDF

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `PDF_ENGINE` | No | `dompdf` | Motor de PDF |
| `PDF_PAPER_SIZE` | No | `A4` | Tamaño del papel (`A4` \| `letter` \| `legal`) |
| `PDF_ORIENTATION` | No | `portrait` | Orientación (`portrait` \| `landscape`) |
| `PDF_INCLUDE_COMPANY_LOGO` | No | `true` | Incluir logo de empresa en el encabezado |
| `PDF_FOOTER_TEXT` | No | `Generado por flexyflow` | Texto del pie de página |
| `PDF_FONT_SIZE` | No | `10` | Tamaño de fuente base (pt) |
| `PDF_MAX_SYNC_ROWS` | No | `500` | Límite máximo de filas por exportación sincrónica |

---

## Almacenamiento, colas y caché

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `BROADCAST_CONNECTION` | No | `log` | `log` (no broadcast) \| `redis` \| `pusher` |
| `FILESYSTEM_DISK` | No | `s3` | `local` \| `public` \| `s3` \| `s3_documents` |
| `QUEUE_CONNECTION` | No | `database` | Stack canónico: PostgreSQL (`database`). `sync` está prohibido — bloquea HTTP. Ver CLAUDE.md §12. |
| `CACHE_STORE` | No | `database` | Stack canónico: PostgreSQL (`database`). `file`/`array` rompen N-instance. |
| `CACHE_PREFIX` | No | — | Prefijo de claves del cache |

### Memcached / Redis (opcional)

| Variable | Default | Descripción |
|----------|---------|-------------|
| `MEMCACHED_HOST` | `127.0.0.1` | Solo si `CACHE_STORE=memcached` |
| `REDIS_CLIENT` | `phpredis` | Cliente PHP para Redis |
| `REDIS_HOST` | `127.0.0.1` | |
| `REDIS_PASSWORD` | `null` | |
| `REDIS_PORT` | `6379` | |

### S3 / MinIO

El template es **neutral**. En qa/pdn las credenciales vienen del IAM instance profile del ASG; los buckets se setean via GH Environment vars.

| Variable | Default | Descripción |
|----------|---------|-------------|
| `AWS_DEFAULT_REGION` | `us-east-1` | Región AWS — debe coincidir con la región de los identities de SES |
| `AWS_BUCKET` | — | Bucket público (assets, logos, imágenes de menú, QR, iconos PWA). qa/pdn: `flexyflow-panel-{qa\|pdn}-assets` |
| `AWS_BUCKET_DOCUMENTS` | — | Bucket privado (PDFs de factura, reportes, evidencia de enrolamiento — DIAN 10 años). qa/pdn: `flexyflow-panel-{qa\|pdn}-documents` |
| `AWS_ENDPOINT` | — | Endpoint custom S3-compatible. Vacío = endpoint nativo AWS |
| `AWS_URL` | — | URL base del bucket público. Vacío = vhost-style automático |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | `false` = vhost-style (AWS S3 real); `true` = path-style (MinIO local) |
| `AWS_ACCESS_KEY_ID` | — | Solo en local (MinIO). En qa/pdn vacío — IAM role del ASG |
| `AWS_SECRET_ACCESS_KEY` | — | Idem |

---

## Reportes

| Variable | Default | Descripción |
|----------|---------|-------------|
| `REPORT_MAX_DATE_RANGE_DAYS` | `90` | Rango máximo de fechas al generar un reporte |
| `REPORT_DOWNLOAD_TTL` | `30` | TTL (minutos) del token de descarga |
| `REPORT_STORAGE_DISK` | `s3_documents` | Disco de almacenamiento (DIAN → bucket privado) |

---

## Caché de configuraciones de empresa

| Variable | Default | Descripción |
|----------|---------|-------------|
| `COMPANY_SETTINGS_CACHE_TTL` | `3600` | TTL en segundos del caché |
| `COMPANY_SETTINGS_CACHE_ENABLED` | `true` | Habilita el caché |

---

## Métricas y dashboard

| Variable | Default | Descripción |
|----------|---------|-------------|
| `METRICS_CACHE_TTL` | `60` | TTL del caché de métricas generales (segundos) |
| `METRICS_POLLING_INTERVAL` | `30` | Intervalo de polling del dashboard (segundos) |
| `METRICS_TOP_DISHES_LIMIT` | `10` | Número máximo de platos destacados |
| `DASHBOARD_ABANDONMENT_ALERT_THRESHOLD` | `15` | Umbral (%) de tasa de abandono para alerta |
| `DASHBOARD_DELIVERY_TIME_ALERT_THRESHOLD` | `45` | Umbral (min) de tiempo de entrega para alerta |
| `DB_SLOW_QUERY_THRESHOLD` | `50` | Umbral (ms) para registrar query lenta en log |
| `DASHBOARD_SUMMARY_CACHE_TTL` | `60` | TTL del summary |
| `DASHBOARD_CHART_CACHE_TTL` | `300` | TTL de gráficos |
| `DASHBOARD_HEATMAP_CACHE_TTL` | `600` | TTL del heatmap |
| `DASHBOARD_METRICS_CACHE_TTL` | `300` | TTL de KPIs |
| `DASHBOARD_CACHE_ENABLED` | `true` | Habilita el caché del dashboard |

---

## Roles y permisos

| Variable | Default | Descripción |
|----------|---------|-------------|
| `SYSTEM_ROLES` | `owner,admin,employee` | Nombres de roles de sistema |
| `DEFAULT_EMPLOYEE_PERMISSIONS` | `orders.read,chats.read,clients.read,loyalty.read` | Permisos por defecto del rol `employee` |

---

## Menú

| Variable | Default | Descripción |
|----------|---------|-------------|
| `MENU_IMAGE_DISK` | `s3` | Disco de imágenes (`s3` \| `public` \| `local`). Nunca `local` en multi-instancia. |
| `MENU_IMAGE_MAX_SIZE_KB` | `2048` | Tamaño máximo de imagen subida |
| `MENU_IMAGE_THUMBNAIL_WIDTH` | `400` | Ancho del thumbnail |
| `MENU_IMAGE_THUMBNAIL_HEIGHT` | `300` | Alto del thumbnail |
| `MENU_MAX_CATEGORIES` | `20` | Límite estructural |
| `MENU_MAX_ITEMS_PER_CATEGORY` | `50` | Límite estructural |

---

## Compras a proveedores

| Variable | Default | Descripción |
|----------|---------|-------------|
| `PURCHASE_ATTACHMENT_DISK` | `s3_documents` | Disco para adjuntos (facturas, notas de entrega) — DIAN 10 años → bucket privado |

---

## Cupones

| Variable | Default | Descripción |
|----------|---------|-------------|
| `COUPON_CODE_MIN_LENGTH` | `4` | Longitud mínima del código |
| `COUPON_CODE_MAX_LENGTH` | `20` | Longitud máxima del código |
| `COUPON_MAX_VALUE_PERCENTAGE` | `80` | Descuento máximo permitido (%) |
| `COUPON_MAX_FIXED_VALUE` | `100000` | Descuento máximo permitido (valor fijo) |
| `COUPON_ENABLE_FIRST_ORDER_VALIDATION` | `true` | Valida que cupones de primer pedido solo apliquen a nuevos clientes |

---

## Fidelización (#122)

| Variable | Default | Descripción |
|----------|---------|-------------|
| `LOYALTY_ENABLED` | `false` | Habilita el programa globalmente (cada empresa puede overridear con `company_settings`) |
| `LOYALTY_POINTS_PER_COP` | `0.001` | Puntos otorgados por cada peso (0.001 = 1 pt cada $1.000 COP) |
| `LOYALTY_REDEMPTION_EXPIRES_MINUTES` | `30` | Minutos que un cupón de canje vive antes de expirar |
| `LOYALTY_REFUND_REVERSES_POINTS` | `true` | Al devolver una orden completada, reversa los puntos otorgados |
| `LOYALTY_EXPIRE_AFTER_MONTHS` | `12` | Meses de inactividad tras los cuales el job `loyalty:expire-stale` expira el balance. 0 desactiva. |
| `LOYALTY_MAX_MANUAL_ADJUST` | `10000` | Tope absoluto de puntos por ajuste manual (anti-abuso de staff) |

---

## Entregas

| Variable | Default | Descripción |
|----------|---------|-------------|
| `DELIVERY_NOTIFY_CLIENT_ON_ASSIGNMENT` | `true` | Notifica al cliente al asignar repartidor |
| `DELIVERY_NOTIFY_CLIENT_ON_COMPLETION` | `true` | Notifica al cliente al completar la entrega |
| `DELIVERY_SHARE_COURIER_PHONE` | `true` | Comparte teléfono del repartidor con el cliente |
| `DELIVERY_MAX_ACTIVE_PER_COURIER` | `3` | Máximo de entregas activas simultáneas por repartidor |

---

## WhatsApp legacy

| Variable | Obligatoria | Descripción |
|----------|-------------|-------------|
| `WHATSAPP_API_KEY` | No | Notificaciones legacy — pendiente de migrar a Cloud API |
| `WHATSAPP_PHONE_NUMBER` | No | |

---

## Meta / WhatsApp Cloud API (Tech Provider)

| Variable | Default | Descripción |
|----------|---------|-------------|
| `META_APP_ID` | `1265007232388204` | App de Meta de flexyflow (compartida QA + PDN) |
| `META_APP_SECRET` | — | App Secret (SECRETO, GitHub Secrets) |
| `META_BUSINESS_ID` | `929046296489964` | Business Manager de flexyflow |
| `META_SYSTEM_USER_ID` | `61573213870387` | System User de partner |
| `META_SYSTEM_USER_TOKEN` | — | Token "Nunca expira" del System User (SECRETO) |
| `META_CONFIG_ID_QA` | `941660645323511` | Embedded Signup configuration ID (qa) |
| `META_CONFIG_ID_PDN` | `2605276259869097` | Embedded Signup configuration ID (pdn) |
| `META_GRAPH_API_VERSION` | `v25.0` | Versión del Graph API |
| `META_WEBHOOK_VERIFY_TOKEN_QA` | — | Verify token del webhook (qa) |
| `META_WEBHOOK_VERIFY_TOKEN_PDN` | — | Verify token del webhook (pdn) |

---

## Web Push (PWA) — #149

| Variable | Default | Descripción |
|----------|---------|-------------|
| `VAPID_PUBLIC_KEY` | — | Clave VAPID pública (P-256 base64url). Generar con `php artisan push:generate-vapid-keys`. Se expone via Inertia shared props. En PDN vive en SSM Parameter Store. |
| `VAPID_PRIVATE_KEY` | — | Clave VAPID privada. Rotar invalida TODAS las subs existentes; los browsers re-suscriben via `pushsubscriptionchange` del SW. |
| `VAPID_SUBJECT` | `mailto:info@flexyflow.co` | Subject de contacto (mailto o URL https) |
| `PUSH_INVENTORY_DIGEST_ENABLED` | `true` | Kill-switch del digest de inventario al login |

---

## API

| Variable | Default | Descripción |
|----------|---------|-------------|
| `RESPONSE_COMPRESSION_ENABLED` | `true` | Compresión gzip de respuestas HTTP |
| `API_DEFAULT_PAGE_SIZE` | `20` | Paginación por defecto |
| `API_MAX_PAGE_SIZE` | `100` | Tope de page size |

---

## Frontend (Vite — `application/frontend/.env`)

El frontend es un proyecto independiente (Vite + React + Cloudflare Worker). Vive en `application/frontend/`, NO dentro de Laravel.

| Variable | Default | Descripción |
|----------|---------|-------------|
| `VITE_APP_NAME` | `${APP_NAME}` | Nombre en `<title>` |
| `VITE_API_URL` | — (vacío en dev) | Origin del backend Laravel (ej. `https://panel-api.flexyflow.co`). Lo usan `apiFetch` y `routeBackend()`. Vacío en dev → paths relativos vía proxy de Vite. En pdn se define en `application/frontend/.env.production` para el build del Worker |

---

## CORS

| Variable | Default | Descripción |
|----------|---------|-------------|
| `CORS_ALLOWED_ORIGINS` | — | Orígenes permitidos para CORS con credenciales (lista separada por coma). Vacío → `config/cors.php` deriva la lista de `FRONTEND_URL` + orígenes locales (`http://localhost`, `:80`, `:5173`). NUNCA usar `*` con credenciales — el config lo rechaza. El patrón `*.flexyflow.co` aplica siempre. |

---

## Wiki

| Variable | Default | Descripción |
|----------|---------|-------------|
| `WIKI_AUTO_UPDATE` | `false` | Si es `true`, un workflow de CI sincroniza `docs/wiki/` al repo `{repo}.wiki.git` después de cada merge a `main`. Por defecto la sincronización es manual. |

---

## Notas

- Cambiar `JWT_SECRET` o `JWT_PAYLOAD_ENCRYPTION_KEY` invalida todos los tokens activos.
- `BILLING_GRACE_MONTHS=0` hace que la mora pase a `delinquent` al día siguiente del vencimiento.
- En entornos sin Redis, `QUEUE_CONNECTION=database` es suficiente para los volúmenes esperados de facturación mensual.
