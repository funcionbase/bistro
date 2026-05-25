# Variables de Entorno

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

Lista de variables `.env` reconocidas por la aplicación, agrupadas por dominio. Las marcadas como **Obligatoria** deben estar definidas en cualquier entorno; las **Opcionales** tienen valor por defecto y solo se sobrescriben para casos especiales.

---

## Aplicación

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `APP_NAME` | Sí | `Laravel` | Nombre mostrado en `<title>` y emails |
| `APP_ENV` | Sí | `production` | `local` \| `staging` \| `production` |
| `APP_KEY` | Sí | — | Clave de cifrado Laravel; generar con `php artisan key:generate` |
| `APP_DEBUG` | Sí | `false` | `true` solo en local |
| `APP_URL` | Sí | `http://localhost` | URL canónica usada en correos y links |
| `APP_TIMEZONE` | No | `UTC` | Zona horaria por defecto |
| `FRONTEND_URL` | No | (según `APP_ENV`) | URL del SPA para el redirect post-OAuth y los orígenes CORS. `config/app.php` la resuelve por `APP_ENV`: `pdn`/`production`→`https://panel.flexyflow.co`, `qa`→`https://panel-qa.flexyflow.co`, local→`http://localhost:5173`. Definila **solo** como override puntual; en `.env.example` va comentada — si se filtra un valor de dev, el callback de Google redirige a `localhost` |

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
| `BOT_JWT_SECRET` | Solo si hay bot | — | Clave HMAC para JWT del bot externo |
| `BOT_JWT_TTL` | No | `86400` | TTL más largo (24h) que JWT de usuario |

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

## Google OAuth

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `GOOGLE_CLIENT_ID` | Sí | — | OAuth Client ID |
| `GOOGLE_CLIENT_SECRET` | Sí | — | OAuth Client Secret |
| `GOOGLE_REDIRECT_URI` | Sí | — | Debe coincidir con la callback registrada en Google Cloud Console |

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

## Facturación

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `BILLING_CURRENCY` | No | `COP` | Moneda |
| `BILLING_GRACE_MONTHS` | No | `2` | Meses de gracia antes de suspender |
| `BILLING_DUE_DAY` | No | `15` | Día del mes de vencimiento |
| `BILLING_GENERATE_DAY` | No | `20` | Día del mes en que se generan facturas |
| `BILLING_OVERDUE_DAY` | No | `16` | Día en que se marcan facturas vencidas |

---

## PDF

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `PDF_DRIVER` | No | `dompdf` | Motor PDF |

---

## Almacenamiento, colas y caché

| Variable | Obligatoria | Default | Descripción |
|----------|-------------|---------|-------------|
| `FILESYSTEM_DISK` | No | `local` | `local` \| `public` \| `s3` |
| `QUEUE_CONNECTION` | No | `database` | `database` \| `redis` |
| `CACHE_STORE` | No | `database` | `database` \| `redis` |
| `METRICS_CACHE_TTL` | No | — | TTL en segundos para métricas (varios sub-keys en `config/metrics.php`) |

S3 (cuando `FILESYSTEM_DISK=s3`):

| Variable | Obligatoria si S3 | Descripción |
|----------|-------------------|-------------|
| `AWS_ACCESS_KEY_ID` | Sí | |
| `AWS_SECRET_ACCESS_KEY` | Sí | |
| `AWS_DEFAULT_REGION` | Sí | |
| `AWS_BUCKET` | Sí | |

---

## Frontend (Vite)

| Variable | Default | Descripción |
|----------|---------|-------------|
| `VITE_APP_NAME` | `${APP_NAME}` | Nombre en `<title>` |
| `VITE_API_URL` | — (vacío en dev) | Host del backend Laravel (origin, ej. `https://panel-api.flexyflow.co`). Lo usan `apiFetch` y `routeBackend()`. Vacío en dev → paths relativos vía proxy de Vite. En PDN se define en `application/frontend/.env.production` para el build del Worker |
| `VITE_PUSHER_APP_KEY` | — | Reservada para WebSocket Pusher (futuro) |
| `VITE_PUSHER_HOST`, `VITE_PUSHER_PORT`, `VITE_PUSHER_SCHEME`, `VITE_PUSHER_APP_CLUSTER` | — | Igual |

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
