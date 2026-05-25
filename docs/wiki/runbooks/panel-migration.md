# Runbook — Cutover a `panel.flexyflow.co`

> **Issue origen**: [#239](https://github.com/cristianmarint/panel-flexyflow-co/issues/239)
> **Tipo**: trabajo de infraestructura coordinado (Cloudflare + AWS + Google Cloud Console).
> **Ventana sugerida**: madrugada CO 02:00–04:00 (UTC-5), bajo tráfico.
> **Duración esperada**: 15–30 min total. Cutover real: ~2–5 min.

Cutover **single-host**: el SPA se mueve a `panel.flexyflow.co`. El subdominio
anterior (`restaurante.flexyflow.co`) se apaga sin redirect ni soporte dual —
quien tenga el bookmark viejo recibe error DNS hasta que reescriba el dominio.

- Backend en **AWS** (EC2 + ALB), DNS en **Cloudflare** (no Route53).
- Frontend en **Cloudflare Workers** (`wrangler deploy`).
- ACM wildcard `*.flexyflow.co` ya cubre `panel.flexyflow.co`.

---

## Pre-requisitos

Antes de iniciar el cutover:

- [ ] PR de #239 mergeado a `main` y deployado a PDN (cambios en
      `config/app.php`, `config/cors.php`, `SecurityHeaders`, manifest PWA,
      `wrangler.jsonc`, CFN params).
- [ ] Snapshot manual de RDS / Supabase tomado y guardado.
- [ ] Google Cloud Console: callback nuevo agregado (Fase B) y smoke-testeado.
- [ ] Equipo en standby (mínimo 1 dev + 1 ops) durante la ventana.
- [ ] Rollback plan revisado.

## Fases

### Fase A — Deploy del Worker `panel-flexyflow-co` (T-1 día o el día)

```bash
cd application/frontend
npm run build
npx wrangler deploy
```

El `wrangler.jsonc` ya tiene `name: "panel-flexyflow-co"`. El deploy crea el
Worker en Cloudflare (o actualiza si ya existe).

**Custom domain**:
1. Cloudflare Dashboard → Workers & Pages → `panel-flexyflow-co` → Settings → Triggers.
2. Add Custom Domain: `panel.flexyflow.co`.
3. Cloudflare crea el record DNS automáticamente.

**Verificar DNS**:
```bash
dig +short panel.flexyflow.co
# Esperado: alguna IP de Cloudflare (104.21.*, 172.67.*, etc.).

curl -I https://panel.flexyflow.co/
# Esperado: HTTP/2 200 + cert válido. El SPA carga normal.
```

> En este punto `panel.flexyflow.co` ya sirve el SPA, pero el backend sigue
> con `APP_URL=https://restaurante.flexyflow.co`. Eso lo cambia la Fase D.

### Fase B — Google Cloud Console (T-1 día)

1. [console.cloud.google.com](https://console.cloud.google.com) → APIs & Services → Credentials.
2. Seleccionar el OAuth 2.0 Client ID que usa Socialite.
3. En **Authorized redirect URIs** **reemplazar**:
   - Remover `https://restaurante.flexyflow.co/auth/google/callback`.
   - Agregar `https://panel.flexyflow.co/auth/google/callback`.
4. Save.

> **Single-host**: no se mantiene el callback viejo. Si por error se hace
> antes del cutover, login con Google desde `restaurante.*` rompe — por eso
> esta fase va junto con la Fase D (no antes).

### Fase C — Verificar ACM + SES (T-1 día)

```bash
aws acm describe-certificate \
  --certificate-arn arn:aws:acm:us-east-1:224458505677:certificate/e3f43ee0-c493-4c62-b032-f0ac51d92c4d \
  --region us-east-1 \
  --query 'Certificate.{Status:Status,Domains:SubjectAlternativeNames}'
```

Esperado: `Status: ISSUED`, SANs incluyen `*.flexyflow.co`.

**SES**:
```bash
aws ses get-identity-verification-attributes \
  --identities flexyflow.co \
  --region us-east-1
```

Esperado: `VerificationStatus: Success`. Los emails post-cutover salen con
`From: noreply@flexyflow.co` (marca raíz). Los links del cuerpo se renderizan
dinámicamente desde `config('app.frontend_url')` — pasan solos a `panel.*`
cuando se cambie `APP_URL`.

### Fase D — Cutover (T+0) ⚡ ventana de mantenimiento

#### Paso 1 — Snapshot (T-15 min)

```bash
aws rds create-db-snapshot \
  --db-instance-identifier flexyflow-restaurante-pdn \
  --db-snapshot-identifier pre-panel-cutover-$(date +%Y%m%d-%H%M)
# Si la BD vive en Supabase, snapshot manual desde el dashboard.
```

#### Paso 2 — Actualizar Secrets Manager (T-5 min)

Vía `.github/workflows/sync-env-secret.yml` o manual con AWS CLI.

Variables que cambian:

```
APP_URL             → https://panel.flexyflow.co
FRONTEND_URL        → (vacío, lo resuelve config/app.php por APP_ENV)
GOOGLE_REDIRECT_URI → https://panel.flexyflow.co/auth/google/callback
SESSION_DOMAIN      → .flexyflow.co  (con el punto inicial)
```

En GitHub Environment `pdn`, actualizar los Variables/Secrets correspondientes
y correr el workflow `sync-env-secret.yml` con `environment=pdn`.

#### Paso 3 — Deploy ASG con env nuevo (T+0)

```bash
aws autoscaling start-instance-refresh \
  --auto-scaling-group-name flexyflow-restaurante-pdn-asg \
  --preferences MinHealthyPercentage=100 \
  --region us-east-1
```

Esperar que la(s) instancia(s) nueva(s) salgan healthy.

Verificar dentro de una instancia:
```bash
aws ssm start-session --target i-XXXXX --region us-east-1
# Dentro:
cat /var/www/flexyflow.restaurante/application/.env | grep -E 'APP_URL|SESSION_DOMAIN'
```

#### Paso 4 — Apagar Worker viejo + DNS (T+1 min)

**Cloudflare Dashboard**:
1. Workers & Pages → `restaurante-flexyflow-co` → Settings → Triggers.
2. **Remove Custom Domain** `restaurante.flexyflow.co`. Esto borra el DNS
   record automáticamente.
3. Opcional: **Delete** del Worker entero. O dejarlo huérfano (no cuesta nada
   sin tráfico) — la decisión es del owner.

Resultado: cualquier request a `restaurante.flexyflow.co` recibe error DNS
(NXDOMAIN). Sin redirect, sin 301, sin transición.

#### Paso 5 — Smoke tests (T+3 min)

```bash
# App responde en el nuevo host
curl -I https://panel.flexyflow.co/
# Esperado: HTTP/2 200

# Host viejo apagado
curl -I https://restaurante.flexyflow.co/
# Esperado: error DNS o "could not resolve host"

# Health del backend (no migra)
curl https://restaurante-api.flexyflow.co/health/ready
# Esperado: 200 OK
```

#### Paso 6 — Validar login E2E (T+5 min)

Con una cuenta de test, sesión limpia (incognito):
1. Entrar a `https://panel.flexyflow.co`.
2. Click "Continuar con Google".
3. Esperar: redirect a Google → callback exitoso → dashboard.
4. Si error `redirect_uri_mismatch`: revisar Fase B (callback no actualizado).

#### Paso 7 — Monitoring (T+5 a T+60 min)

Cloudflare → Analytics:
- Request rate `panel.flexyflow.co` ramping up.
- `restaurante.flexyflow.co` → 0 (DNS apagado).

CloudWatch en AWS:
- ALB `HTTPCode_Target_5XX_Count` plano.
- EC2 CPU normal.

## Rollback

Si en los primeros 15 min hay incidente crítico (login roto, errores masivos):

1. **Restaurar Worker viejo**: en Cloudflare Workers, re-agregar custom domain
   `restaurante.flexyflow.co` al Worker `restaurante-flexyflow-co` (o crear de
   nuevo si lo borraste).
2. **Restaurar Secrets Manager**:
   - `APP_URL` ← `https://restaurante.flexyflow.co`
   - `GOOGLE_REDIRECT_URI` ← `https://restaurante.flexyflow.co/auth/google/callback`
3. **Google Cloud Console**: restaurar callback viejo.
4. **Instance refresh**: `aws autoscaling start-instance-refresh` con env viejo.

Rollback total: **< 20 min**. El factor lento es el DNS de Cloudflare
propagándose (TTL 60s para subdominios proxied → casi instantáneo).

## Post-cutover

- [ ] Apagar el Worker viejo `restaurante-flexyflow-co` si quedó huérfano.
- [ ] Comentar en #239 con métricas finales: request rate en `panel.*`,
      login success rate, 5xx.
- [ ] Cerrar el issue cuando 7 días pasen sin incidente.

---

## Apéndice — Comandos AWS CLI útiles

```bash
# Listar instancias del ASG PDN
aws autoscaling describe-auto-scaling-groups \
  --auto-scaling-group-names flexyflow-restaurante-pdn-asg \
  --region us-east-1 --query 'AutoScalingGroups[0].Instances'

# Logs de la EC2 (vía SSM)
aws ssm start-session --target i-XXXXX --region us-east-1
# Luego dentro: tail -f /var/www/flexyflow.restaurante/application/storage/logs/laravel.log

# Estado del cert ACM
aws acm list-certificates --region us-east-1 \
  --query 'CertificateSummaryList[?contains(DomainName,`flexyflow.co`)]'
```

> Última revisión: 2026-05-25 (#239 — cutover single-host sin redirect).
