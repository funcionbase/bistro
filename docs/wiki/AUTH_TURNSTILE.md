# Cloudflare Turnstile — captcha anti-spam en auth

> Estado: Activo en pdn. Complementa los rate limiters + honeypot del acceso dual.

## Qué protege

Widget de Cloudflare Turnstile (captcha, modo **Managed** — casi siempre invisible) en los 3 endpoints de auth que crean cuenta o emiten correo:

- `POST /api/v1/auth/login` — freno a credential-stuffing distribuido (además del lockout 5/60s por email+IP).
- `POST /api/v1/auth/register` — evita altas masivas de bots (cada alta puede disparar un correo SES).
- `POST /api/v1/auth/forgot-password` — evita mail-bombing por SES.

No se aplica a `reset-password`, `verify`, ni `verification/resend*` (ya van detrás de un token propio / rate limiter).

## Cómo funciona

- **Frontend**: `components/turnstile.tsx` carga el script de Cloudflare y renderiza el widget (site key público `VITE_TURNSTILE_SITE_KEY`, horneado en el bundle por `.env.production`). El token viaja en el body como `cf-turnstile-response`. Los forms bloquean el submit hasta tener token (solo si el site key está configurado).
- **Backend**: middleware `turnstile` (`EnsureTurnstileToken`) verifica el token contra el `siteverify` de Cloudflare con `TURNSTILE_SECRET_KEY` (solo en el `.env` del backend). Corre ANTES del throttle.

## Fail-open (disponibilidad > protección ante caída de terceros)

El middleware **deja pasar** (no bloquea) cuando:
- `TURNSTILE_SECRET_KEY` no está configurado (local/qa sin claves) → captcha desactivado.
- El `siteverify` de Cloudflare es inalcanzable/timeout → no dejamos a usuarios legítimos afuera por un incidente de Cloudflare; los rate limiters siguen de backstop.

Solo rechaza (422 `captcha`) cuando Cloudflare responde explícitamente que el token es inválido/ausente estando la protección activa. El frontend también es fail-open: si el script no carga, no bloquea el submit.

## Claves y configuración

| Clave | Dónde vive | Notas |
|---|---|---|
| Site key (público) | `bistro/frontend/.env.production` → `VITE_TURNSTILE_SITE_KEY` | Se hornea en el bundle. Cambiarla requiere rebuild + `wrangler deploy`. |
| Secret key | GH Environment `pdn` → secret `TURNSTILE_SECRET_KEY` → `sync-env-secret.yml` → AWS Secrets Manager `bistro-bistro/pdn/dotenv` → `.env` de la EC2 | Nunca en el frontend ni commiteada. |

Widget en Cloudflare: **Turnstile → `bistro-auth`** (hostname `bistro.example.com`, modo Managed). Para rotar claves: regenerar en el dashboard, actualizar `.env.production` (rebuild+deploy frontend) y el secret `TURNSTILE_SECRET_KEY` (re-sync + App Deploy backend).

## Activar en qa (opcional)

Crear otro widget con hostname de qa, poner `VITE_TURNSTILE_SITE_KEY` en el env de build de qa y `TURNSTILE_SECRET_KEY` en el Environment qa. Sin eso, qa corre fail-open (sin captcha).
