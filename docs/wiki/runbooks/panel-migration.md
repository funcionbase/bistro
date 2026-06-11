# Runbook — Cutover a `panel.flexyflow.co` + `panel-api.flexyflow.co`

> **Issue origen**: [#239](https://github.com/cristianmarint/panel-flexyflow-co/issues/239)
> **Tipo**: trabajo de infraestructura coordinado (Cloudflare + AWS + Google Cloud Console).
> **Ventana sugerida**: madrugada CO 02:00–04:00 (UTC-5), bajo tráfico.
> **Duración esperada**: 20–40 min total. Cutover real: ~5 min.

Cutover **single-host** simultáneo de SPA y API:

| Componente | Antes | Después |
|---|---|---|
| Frontend SPA | `restaurante.flexyflow.co` | `panel.flexyflow.co` |
| Backend API | `restaurante-api.flexyflow.co` | `panel-api.flexyflow.co` |

Los hosts anteriores se apagan en el cutover (sin redirect, sin soporte dual).
Quien tenga bookmarks viejos recibe NXDOMAIN.

- Backend en **AWS** (EC2 + ALB), DNS en **Cloudflare** (no Route53).
- Frontend en **Cloudflare Workers** (`wrangler deploy`).
- ACM wildcard `*.flexyflow.co` ya cubre ambos hosts nuevos.

---

## Pre-requisitos

Antes de iniciar el cutover:

- [ ] PR de #239 mergeado a `main` y deployado a PDN (cambios en
      `config/app.php`, `config/cors.php`, `SecurityHeaders`, manifest PWA,
      `wrangler.jsonc`, CFN params, `.env.production`).
- [ ] Snapshot manual de RDS / Supabase tomado y guardado.
- [ ] Google Cloud Console: callback nuevo identificado y planeado (Fase D).
- [ ] Equipo en standby (mínimo 1 dev + 1 ops) durante la ventana.
- [ ] Rollback plan revisado.

## Fases

### Fase A — Deploy del Worker `panel-flexyflow-co` (T-1 día)

```bash
cd bistro/frontend
npm run build
npx wrangler deploy
```

El `wrangler.jsonc` ya tiene `name: "panel-flexyflow-co"`. El deploy crea el
Worker en Cloudflare.

**Custom domain**:
1. Cloudflare Dashboard → Workers & Pages → `panel-flexyflow-co` → Settings → Triggers.
2. Add Custom Domain: `panel.flexyflow.co`.
3. Cloudflare crea el record DNS automáticamente.

**Verificar**:
```bash
dig +short panel.flexyflow.co
curl -I https://panel.flexyflow.co/
# Esperado: HTTP/2 200 + cert válido.
```

> Hasta este punto el SPA en `panel.flexyflow.co` apunta al API viejo —
> sirve la página estática, pero los fetch fallan por CORS hasta Fase B.

### Fase B — CNAME `panel-api.flexyflow.co` (T-1 día)

**Cloudflare DNS**:

1. Cloudflare Dashboard → DNS → Records → Add record.
2. Type: `CNAME`. Name: `panel-api`. Target: el DNS-name del ALB (output
   `LoadBalancerDnsName` del stack `05-alb`, ej.
   `flexyflow-panel-pdn-alb-1234567890.us-east-1.elb.amazonaws.com`).
3. Proxy status: **Proxied (naranja)**. TTL: Auto.
4. Save.

**Verificar**:
```bash
dig +short panel-api.flexyflow.co
# Esperado: IPs de Cloudflare proxy.

# Pegarle al ALB con el host nuevo — el ALB todavía está configurado
# para responder con Host=restaurante-api, así que esto va a dar 403:
curl -I -H 'Host: panel-api.flexyflow.co' https://panel-api.flexyflow.co/health/ready
# Esperado: HTTP/2 403 (host header no matchea el listener rule actual).
```

> El 403 es lo esperado en este punto. La Fase E aplica el CFN nuevo
> (`PublicHostname=panel-api.flexyflow.co`) que cambia la regla del ALB.

### Fase C — Snapshot RDS (T-15 min)

```bash
aws rds create-db-snapshot \
  --db-instance-identifier flexyflow-panel-pdn \
  --db-snapshot-identifier pre-panel-cutover-$(date +%Y%m%d-%H%M)
# Si la BD vive en Supabase, snapshot manual desde el dashboard.
```

### Fase D — Google Cloud Console (T-5 min)

1. [console.cloud.google.com](https://console.cloud.google.com) → APIs & Services → Credentials.
2. Seleccionar el OAuth 2.0 Client ID que usa Socialite.
3. En **Authorized redirect URIs** **reemplazar**:
   - Remover `https://restaurante-api.flexyflow.co/auth/google/callback`.
   - Agregar `https://panel-api.flexyflow.co/auth/google/callback`.
4. Save.

> **Importante**: el callback de Google va al **backend API**, no al SPA.
> Es el backend quien dispara `Socialite::redirect()` y recibe el callback.

### Fase E — Cutover (T+0) ⚡ ventana de mantenimiento

#### Paso 1 — CFN apply con PublicHostname nuevo

```bash
# Desde la raíz del repo:
aws cloudformation update-stack \
  --stack-name flexyflow-panel-pdn-05-alb \
  --use-previous-template \
  --parameters file://aws/iac/cloudformation/parameters/pdn.json \
  --region us-east-1 \
  --capabilities CAPABILITY_NAMED_IAM
```

> El `pdn.json` ya tiene `PublicHostname=panel-api.flexyflow.co` y
> `AppDomain=panel-api.flexyflow.co`. Esto:
> - Reescribe la `HttpsHostRule` del ALB para que solo deje pasar al TG el
>   tráfico con `Host=panel-api.flexyflow.co`.
> - El default action sigue siendo `fixed-response 403`.
>
> El cambio aplica en segundos. **Después de este apply**, requests con
> `Host=restaurante-api.*` reciben 403 — pero todavía no hay tráfico
> productivo por allí porque el SPA viejo va a su mismo API viejo (el ALB
> es el mismo, solo cambió la regla).

#### Paso 2 — Actualizar Secrets Manager

Variables que cambian:

```
APP_URL             → https://panel.flexyflow.co
FRONTEND_URL        → (vacío, lo resuelve config/app.php por APP_ENV)
GOOGLE_REDIRECT_URI → https://panel-api.flexyflow.co/auth/google/callback
SESSION_DOMAIN      → .flexyflow.co
```

En GitHub Environment `pdn`, actualizar y correr el workflow
`sync-env-secret.yml` con `environment=pdn`.

#### Paso 3 — Deploy ASG con env nuevo

```bash
aws autoscaling start-instance-refresh \
  --auto-scaling-group-name flexyflow-panel-pdn-asg \
  --preferences MinHealthyPercentage=100 \
  --region us-east-1
```

Esperar que la(s) instancia(s) nueva(s) salgan healthy. El UserData de la
EC2 levanta nginx con `server_name=panel-api.flexyflow.co` derivado del
`AppDomain` del CFN.

Verificar dentro de una instancia:
```bash
aws ssm start-session --target i-XXXXX --region us-east-1
# Dentro:
cat /var/www/panel.flexyflow/application/.env | grep -E 'APP_URL|SESSION_DOMAIN|GOOGLE_REDIRECT_URI'
sudo nginx -T 2>/dev/null | grep server_name
```

#### Paso 4 — Apagar Cloudflare records viejos

**Cloudflare Dashboard** → DNS → Records:

1. **Remove Custom Domain** `restaurante.flexyflow.co` del Worker viejo
   `restaurante-flexyflow-co` (Workers & Pages → Settings → Triggers).
2. **Eliminar el record DNS** `restaurante-api` (CNAME que apuntaba al ALB).

Resultado: cualquier request a los hosts viejos recibe NXDOMAIN.

#### Paso 5 — Smoke tests

```bash
# SPA nuevo responde
curl -I https://panel.flexyflow.co/
# Esperado: HTTP/2 200

# API nuevo responde
curl -I https://panel-api.flexyflow.co/health/ready
# Esperado: HTTP/2 200

# Hosts viejos apagados
curl -I https://restaurante.flexyflow.co/
curl -I https://restaurante-api.flexyflow.co/
# Esperado: error DNS / "could not resolve host"
```

#### Paso 6 — Validar login E2E

Cuenta de test, sesión limpia (incognito):
1. Entrar a `https://panel.flexyflow.co`.
2. Click "Continuar con Google".
3. Esperar: redirect a Google → callback a `panel-api.flexyflow.co/auth/google/callback`
   → dashboard.
4. Si error `redirect_uri_mismatch`: revisar Fase D (callback no actualizado).

#### Paso 7 — Monitoring (T+5 a T+60 min)

Cloudflare → Analytics:
- Request rate `panel.flexyflow.co` + `panel-api.flexyflow.co` rampando.
- Hosts viejos en 0.

CloudWatch en AWS:
- ALB `HTTPCode_Target_5XX_Count` plano.
- EC2 CPU normal.

## Rollback

Si en los primeros 15 min hay incidente crítico:

1. **Cloudflare DNS**: re-crear el record `restaurante-api` (CNAME al ALB) y
   re-asociar el custom domain `restaurante.flexyflow.co` al Worker viejo.
2. **CFN rollback**: revertir `PublicHostname` y `AppDomain` a
   `restaurante-api.flexyflow.co` en `pdn.json` y volver a aplicar.
3. **Secrets Manager**: revertir `APP_URL`, `GOOGLE_REDIRECT_URI` a los hosts
   viejos.
4. **Google Cloud Console**: restaurar callback viejo.
5. **Instance refresh** con env viejo.

Rollback total: **< 25 min**. El paso lento es la propagación DNS de
Cloudflare (TTL 60s para subdominios proxied → casi instantáneo) y el
CFN update del ALB (~2 min).

## Post-cutover

- [ ] Apagar el Worker viejo `restaurante-flexyflow-co` si quedó huérfano.
- [ ] Comentar en #239 con métricas finales.
- [ ] Cerrar el issue cuando 7 días pasen sin incidente.

---

## Apéndice — Comandos AWS CLI útiles

```bash
# Listar instancias del ASG PDN
aws autoscaling describe-auto-scaling-groups \
  --auto-scaling-group-names flexyflow-panel-pdn-asg \
  --region us-east-1 --query 'AutoScalingGroups[0].Instances'

# Logs de la EC2 (vía SSM)
aws ssm start-session --target i-XXXXX --region us-east-1
# Luego dentro: tail -f /var/www/panel.flexyflow/application/storage/logs/laravel.log

# Estado del cert ACM
aws acm list-certificates --region us-east-1 \
  --query 'CertificateSummaryList[?contains(DomainName,`flexyflow.co`)]'

# DNS-name del ALB (para el CNAME en Cloudflare)
aws cloudformation describe-stacks \
  --stack-name flexyflow-panel-pdn-05-alb \
  --region us-east-1 \
  --query 'Stacks[0].Outputs[?OutputKey==`LoadBalancerDnsName`].OutputValue' \
  --output text
```

> Última revisión: 2026-05-25 (#239 — cutover single-host SPA + API).
