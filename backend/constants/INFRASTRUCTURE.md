# INFRASTRUCTURE — DNS, hosts, certs, deploy

> **Fuente de verdad ejecutable**: `aws/iac/cloudformation/parameters/*.json` +
> `application/frontend/wrangler.jsonc` + `application/backend/config/app.php`.
> Este archivo documenta el mapa de hosts y dependencias entre proveedores
> para que cualquier cambio (rename de subdominio, rotación de cert, ALB
> recreado) no requiera arqueología.

---

## Stack actual

| Componente | Proveedor | Detalle |
|---|---|---|
| Dominio raíz | GoDaddy | `flexyflow.co` (registrar). NS delegados a Cloudflare. |
| DNS | Cloudflare | Zona `flexyflow.co` — todos los records. NO usamos Route53 a pesar de que el CFN tenga `Route53HostedZoneId` parametrizable. |
| Frontend SPA | Cloudflare Workers | `npx wrangler deploy` desde `application/frontend/`. Worker: `panel-flexyflow-co`. |
| Backend API | AWS (EC2 + ALB) | ASG `t3.micro` + ALB internet-facing. Cloudflare proxea `panel-api.flexyflow.co` delante del ALB en modo Full. |
| TLS | ACM (us-east-1) | Wildcard `*.flexyflow.co` validado por DNS en Cloudflare. ARN en `pdn.json` → `CertificateArn`. |
| Storage | AWS S3 | Buckets `flexyflow-panel-pdn-assets` (público vía storage-proxy firmado) y `flexyflow-panel-pdn-documents` (privado, DIAN 10 años). |
| Email | AWS SES | Identidad `flexyflow.co` verificada. `From: noreply@flexyflow.co`. |

## Hosts por entorno

| Entorno | Frontend SPA (host) | Backend API (host) | ALB (DNS interno) |
|---|---|---|---|
| Local | `http://localhost:5173` | `http://localhost` | — |
| QA | `panel-qa.flexyflow.co` | `panel-api-qa.flexyflow.co` | output `LoadBalancerDnsName` del stack `05-alb` |
| PDN | `panel.flexyflow.co` | `panel-api.flexyflow.co` | output `LoadBalancerDnsName` del stack `05-alb` |

> En el cutover #239 ambos hosts anteriores (`restaurante.flexyflow.co` y
> `restaurante-api.flexyflow.co`) se apagan sin redirect ni soporte dual.
> Los bookmarks viejos reciben NXDOMAIN.

## Cómo el tráfico llega a la EC2

```
Browser → Cloudflare (proxied, naranja) → ALB:443 → Target Group → EC2:80 (nginx → PHP-FPM)
```

Detalles importantes:

1. **Cloudflare es el TLS público** (cert "Edge Certificate" del plan Cloudflare).
   El ALB acepta solo IPs de Cloudflare (`CloudflareIpRanges` en CFN params).
   Mantener sincronizado con [cloudflare.com/ips](https://www.cloudflare.com/ips/).
2. **ALB termina TLS con el cert ACM** wildcard. Cloudflare habla HTTPS al
   origen (modo Full).
3. **ALB filtra por `Host:` header**: solo deja pasar al TG el tráfico cuyo
   `Host` coincida con `PublicHostname` (`panel-api.flexyflow.co`).
   Cualquier otro host recibe 403 sin tocar EC2.
4. **Nginx** (en EC2) usa `server_name` = `AppDomain` del CFN. Si Cloudflare
   manda un Host distinto, nginx también devuelve 444/error.

## Configuración compartida backend ↔ frontend para hosts

### Backend (`config/app.php`)

```php
'frontend_url' => env('FRONTEND_URL') ?: match (env('APP_ENV')) {
    'pdn', 'production' => 'https://panel.flexyflow.co',
    'qa'                => 'https://panel-qa.flexyflow.co',
    default             => 'http://localhost:5173',
},
```

Lo consume:
- `config/cors.php` → suma a la allowlist.
- `app/Http/Middleware/SecurityHeaders.php` → CSP `connect-src`.
- `app/Http/Controllers/FrontendRedirectController.php` → redirect post-OAuth.
- Templates de email (`vendor/mail/html/message.blade.php`) → footer dinámico.

### Frontend (`.env.production`)

```dotenv
VITE_API_URL=https://panel-api.flexyflow.co
```

## Migración #239 — rename de SPA y API

Cutover atómico, single-host, sin redirect ni soporte dual:

| Componente | Antes | Después |
|---|---|---|
| Frontend SPA | `restaurante.flexyflow.co` | `panel.flexyflow.co` |
| Backend API | `restaurante-api.flexyflow.co` | `panel-api.flexyflow.co` |

1. Deploy del Worker `panel-flexyflow-co` desde `application/frontend/dist`.
2. Asociar custom domain `panel.flexyflow.co` desde Cloudflare → Workers → Triggers.
3. Crear CNAME `panel-api` en Cloudflare apuntando al DNS-name del ALB (proxied).
4. CFN apply de `05-alb` con `pdn.json` (`PublicHostname=panel-api.flexyflow.co`) — reescribe la host-header rule del ALB.
5. Google Cloud Console: reemplazar callback `restaurante-api.flexyflow.co/auth/google/callback` por `panel-api.flexyflow.co/auth/google/callback`.
6. Secrets Manager: cambiar `APP_URL`, `GOOGLE_REDIRECT_URI`, `SESSION_DOMAIN=.flexyflow.co`.
7. `aws autoscaling start-instance-refresh` para que el ASG levante con el env y nginx `server_name` nuevos.
8. Apagar custom domain del Worker viejo y eliminar el CNAME `restaurante-api`.

Runbook detallado paso a paso: [`docs/wiki/runbooks/panel-migration.md`](../../../docs/wiki/runbooks/panel-migration.md).

## Recursos AWS críticos (NO borrar sin aprobación)

| Recurso | Tag/Name | Notas |
|---|---|---|
| ALB | `flexyflow-panel-pdn-alb` | Recrearlo cambia el DNS name → hay que actualizar manualmente el CNAME `panel-api` en Cloudflare. |
| Cert ACM | `arn:aws:acm:us-east-1:224458505677:certificate/e3f43ee0-c493-4c62-b032-f0ac51d92c4d` | Wildcard `*.flexyflow.co`. Si rota, actualizar `CertificateArn` en `pdn.json`. |
| S3 buckets | `flexyflow-panel-pdn-assets` / `flexyflow-panel-pdn-documents` | Documents tiene retención 10 años (DIAN). |
| Secrets Manager | `flexyflow-panel/pdn/dotenv` | Lo hidrata el workflow `sync-env-secret.yml`. |
| Secrets Manager | `flexyflow-panel/pdn/github-pat` | PAT para clonar el repo en el UserData. |

## DNS — records en Cloudflare

Los records vivos (a inspeccionar desde el dashboard Cloudflare):

| Tipo | Nombre | Destino | Proxied? | Notas |
|---|---|---|---|---|
| CNAME | `panel` | Worker `panel-flexyflow-co` | Sí (naranja) | Frontend SPA. Creado por Cloudflare al asociar custom domain. |
| CNAME | `panel-api` | DNS-name del ALB | Sí (naranja) | API backend. |
| CNAME | `panel-qa` | Worker QA | Sí | Frontend QA. |
| CNAME | `panel-api-qa` | DNS-name del ALB QA | Sí | API backend QA. |
| TXT, MX | varios | SES + Cloudflare Email Routing | — | DKIM/SPF/DMARC + buzón soporte. Ver `docs/wiki/EMAIL_SES_SETUP.md`. |

> Post-cutover #239 los records `restaurante` y `restaurante-api` quedan
> removidos — los bookmarks viejos reciben NXDOMAIN.

## Pares espejo que deben mantenerse sincronizados

- `application/backend/config/app.php` ↔ `application/backend/.env.example` (`FRONTEND_URL`).
- `application/backend/config/cors.php` ↔ `config/app.php` (deriva la allowlist del frontend_url).
- `aws/iac/cloudformation/parameters/{qa,pdn}.json` (`PublicHostname`, `AppDomain`, `AllowedCorsOrigins`, `CertificateArn`).
- `application/frontend/wrangler.jsonc` — `name` y custom domain en Cloudflare.
- `application/frontend/.env.production` (`VITE_API_URL`).
- Google Cloud Console — Authorized redirect URI.

> Última revisión: 2026-05-25 (#239 — cutover single-host sin redirect).
