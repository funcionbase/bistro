# Runbook — Migración `restaurante.flexyflow.co` → `panel.flexyflow.co`

> **Issue origen**: [#239](https://github.com/cristianmarint/flexyflow.restaurante/issues/239)
> **Tipo**: trabajo de infraestructura coordinado (Cloudflare + AWS + Google Cloud Console).
> **Ventana sugerida**: madrugada CO 02:00–04:00 (UTC-5), bajo tráfico.
> **Duración esperada**: 30–60 min total. Cutover real: ~5 min.

Este runbook concreta el plan del issue #239 a la infra real:
- Backend en **AWS** (EC2 + ALB), DNS en **Cloudflare** (no Route53).
- Frontend en **Cloudflare Workers** (`wrangler deploy`).
- ACM wildcard `*.flexyflow.co` ya cubre `panel.flexyflow.co`.

---

## Pre-requisitos (hard gate)

Antes de iniciar el cutover, confirmar **TODO** lo siguiente:

- [ ] PR de #239 mergeado a `main` y deployado a PDN (cambios de código en
      `config/app.php`, `config/cors.php`, `SecurityHeaders`, manifest PWA,
      `MigrationBanner`, params CFN).
- [ ] Banner visible en `restaurante.flexyflow.co` para usuarios autenticados.
- [ ] Snapshot manual de RDS / Supabase tomado y guardado.
- [ ] Google Cloud Console: callback nuevo agregado (ver Fase 4 abajo) y testeado
      con cuenta dummy.
- [ ] Equipo en standby en grupo de mensajería (mínimo 1 dev + 1 ops).
- [ ] Rollback plan revisado y testeable.

## Plan por fases (la ejecución es secuencial)

### Fase A — Crear Worker `panel-flexyflow-co` (T-7 días)

**Cloudflare Workers**:

```bash
cd application/frontend
npm run build
npx wrangler deploy --name panel-flexyflow-co
```

Verificar que el deploy aparece como Worker independiente en el dashboard.

**Custom domain**:
1. Cloudflare Dashboard → Workers & Pages → `panel-flexyflow-co` → Settings → Triggers.
2. Add Custom Domain: `panel.flexyflow.co`.
3. Cloudflare crea automáticamente el record DNS si la zona ya está delegada.

**Verificar DNS**:
```bash
dig +short panel.flexyflow.co
# Esperado: alguna IP de Cloudflare (104.21.*, 172.67.*, etc.).

curl -I https://panel.flexyflow.co/
# Esperado: HTTP/2 200 + cert válido. El SPA carga normal.
```

### Fase B — Verificar ACM + ALB (T-7 días)

```bash
aws acm describe-certificate \
  --certificate-arn arn:aws:acm:us-east-1:224458505677:certificate/e3f43ee0-c493-4c62-b032-f0ac51d92c4d \
  --region us-east-1 \
  --query 'Certificate.{Status:Status,Domains:SubjectAlternativeNames}'
```

Esperado: `Status: ISSUED`, SANs incluyen `*.flexyflow.co`.

**No tocar el ALB** — el subdominio del API (`restaurante-api.flexyflow.co`)
no migra en este issue. El ALB y todo su listener queda igual.

### Fase C — Google Cloud Console (T-7 días)

1. Ir a [console.cloud.google.com](https://console.cloud.google.com) → APIs & Services → Credentials.
2. Seleccionar el OAuth 2.0 Client ID que usa Socialite.
3. En **Authorized redirect URIs** agregar:
   - `https://panel.flexyflow.co/auth/google/callback`
   - (Mantener `https://restaurante.flexyflow.co/auth/google/callback`.)
4. Save.
5. **Smoke test**: en una sesión limpia (incognito), entrar a `https://panel.flexyflow.co`,
   click "Continuar con Google". Debe completar el flujo sin error de `redirect_uri_mismatch`.

> NO remover el URI viejo hasta 90 días post-cutover.

### Fase D — SES (verificar) (T-7 días)

```bash
aws ses get-identity-verification-attributes \
  --identities flexyflow.co \
  --region us-east-1
```

Esperado: `VerificationStatus: Success`. Si no:
- Identity `flexyflow.co` ya está verificada (no `restaurante.flexyflow.co`).
- DKIM debe estar publicado como CNAME en Cloudflare.
- Ver `docs/wiki/EMAIL_SES_SETUP.md` para detalle.

Los emails post-cutover van a salir con `From: noreply@flexyflow.co` (marca raíz,
no atada a subdominio) y los links del cuerpo se renderizan dinámicamente desde
`config('app.frontend_url')` — pasarán solos a `panel.*` cuando se cambie `APP_URL`.

### Fase E — Comunicación pre-cutover (T-14 a T-1)

| T- | Canal | Mensaje |
|---|---|---|
| 14 días | Banner in-app | Ya activo (PanelMigrationBanner se auto-renderiza en `restaurante.*`). |
| 14 días | Email | Subject: "Estamos mejorando FlexyFlow — pequeño cambio en tu acceso". |
| 7 días | Email | Recordatorio. |
| 1 día | Banner | Variante `warning` (cambiar copy si fuera necesario). |
| 0 | Email | "Listo — ya estamos en panel.flexyflow.co". |

Templates de email viven en `application/backend/resources/views/emails/migration/`
(crear cuando se decida la fecha — fuera del PR de código).

### Fase F — Cutover (T+0) ⚡ ventana de mantenimiento

#### Paso 1 — Snapshot (T-15 min)

```bash
# Si la BD vive en RDS:
aws rds create-db-snapshot \
  --db-instance-identifier flexyflow-restaurante-pdn \
  --db-snapshot-identifier pre-panel-cutover-$(date +%Y%m%d-%H%M)

# Si vive en Supabase, snapshot manual desde el dashboard.
```

#### Paso 2 — Actualizar Secrets Manager (T-5 min)

Vía `.github/workflows/sync-env-secret.yml` o manual con AWS CLI:

```bash
# Variables que cambian:
#   APP_URL             → https://panel.flexyflow.co
#   FRONTEND_URL        → (vacío, lo resuelve config/app.php automáticamente)
#   LEGACY_FRONTEND_URL → https://restaurante.flexyflow.co (queda 90 días)
#   GOOGLE_REDIRECT_URI → https://panel.flexyflow.co/auth/google/callback
#   SESSION_DOMAIN      → .flexyflow.co  (con el punto inicial — sin esto las
#                         cookies no viajan entre subdominios; #239 documenta)
#   MAIL_FROM_ADDRESS   → noreply@flexyflow.co (sin cambio si ya estaba así)
```

En GitHub Environment `pdn`, actualizar los Variables/Secrets correspondientes
y correr `sync-env-secret.yml` (environment=pdn).

#### Paso 3 — Deploy ASG (T+0)

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
cat /var/www/flexyflow.restaurante/application/.env | grep -E 'APP_URL|SESSION_DOMAIN|LEGACY_FRONTEND_URL'
```

#### Paso 4 — Worker viejo a 301 (T+0 a T+2)

**Opción A — Reemplazar el Worker `restaurante-flexyflow-co` con uno minimal**:

Crear archivo `application/frontend/redirect-worker.js` (no checkearlo en el repo,
es solo para este cutover):

```js
export default {
  async fetch(request) {
    const url = new URL(request.url);
    return Response.redirect(`https://panel.flexyflow.co${url.pathname}${url.search}`, 301);
  },
};
```

Deployar:
```bash
npx wrangler deploy redirect-worker.js \
  --name restaurante-flexyflow-co \
  --compatibility-date 2026-05-20
```

Esto reemplaza el Worker (que servía el SPA) con uno que solo hace 301. La
asociación del custom domain `restaurante.flexyflow.co` se mantiene — apunta
al mismo Worker, solo cambió el código.

**Opción B — Cloudflare Page Rule / Redirect Rule**:

1. Dashboard → Rules → Redirect Rules → Create.
2. Pattern: hostname equals `restaurante.flexyflow.co`.
3. Action: Static redirect → `https://panel.flexyflow.co${request_path}${request_query}`, status 301.
4. Esto requiere desconectar el Worker custom domain antes (porque la regla no se aplica si Worker sirve la request).

Recomendación: **Opción A** — más predecible que jugar con el orden de Worker/Rules.

#### Paso 5 — Smoke tests (T+5 min)

```bash
# Redirect base
curl -I https://restaurante.flexyflow.co/
# Esperado: HTTP/2 301, location: https://panel.flexyflow.co/

# Path preservado
curl -I https://restaurante.flexyflow.co/dashboard
# Esperado: 301 → https://panel.flexyflow.co/dashboard

# Query string preservado
curl -I 'https://restaurante.flexyflow.co/orders?status=pending&page=2'
# Esperado: 301 → https://panel.flexyflow.co/orders?status=pending&page=2

# App responde en el nuevo host
curl -I https://panel.flexyflow.co/
# Esperado: HTTP/2 200

# Health del backend
curl https://restaurante-api.flexyflow.co/health/ready
# Esperado: 200 OK
```

#### Paso 6 — Validar sesión cross-subdomain (T+7 min)

Con una cuenta de test:
1. Loguearse en `panel.flexyflow.co`.
2. En otra pestaña entrar a `https://restaurante.flexyflow.co/dashboard`.
3. Esperar: redirect 301 → `https://panel.flexyflow.co/dashboard` y **seguir logueado**.
4. Si pide login: rollback inmediato — las cookies no se migraron al domain padre.

#### Paso 7 — Monitoring (T+5 a T+60 min)

Dashboard de Cloudflare → Analytics. Buscar:
- Request rate `panel.flexyflow.co` ramping up.
- Request rate `restaurante.flexyflow.co` colapsando a redirects (status 301).
- 5xx en ambos hosts ≤ baseline.

CloudWatch en AWS:
- ALB metrics `HTTPCode_Target_5XX_Count` plano.
- EC2 CPU normal.

## Rollback

Si en los primeros 15 min hay incidente crítico (login roto, errores masivos):

1. Re-deployar el Worker viejo `restaurante-flexyflow-co` con el `wrangler.jsonc`
   actual (sirve el SPA, no el 301).
2. Revertir Secrets Manager:
   - `APP_URL` ← `https://restaurante.flexyflow.co`
3. `aws autoscaling start-instance-refresh` con el env viejo.
4. Banner in-app variante "incidente": "Estamos investigando un problema".
5. Post-mortem en sub-issue.

Rollback total: **< 15 min**.

## Cleanup (T+90 días)

- [ ] Verificar Cloudflare Analytics: tráfico residual a `restaurante.*` <10%.
- [ ] Verificar 0 violaciones CSP en `audit_logs` con `action='csp.violation'`.
- [ ] Google Cloud Console: remover callback `https://restaurante.flexyflow.co/auth/google/callback`.
- [ ] Backend env: vaciar `LEGACY_FRONTEND_URL` (override en GH Environment, sin redeploy).
- [ ] CFN params: actualizar `AllowedCorsOrigins` para quitar el host legacy.
- [ ] Comentario de cierre en #239 con métricas finales.
- [ ] Mantener el Worker `restaurante-flexyflow-co` con 301 **mínimo 1 año más**
      (bookmarks, emails antiguos, QR codes impresos).

## Cleanup eventual (T+1 año)

- [ ] Decisión owner: ¿retirar el Worker de 301?
- [ ] Si sí: remover custom domain `restaurante.flexyflow.co` y el Worker.
- [ ] Si no: dejarlo indefinido — costo es trivial (~$0 con plan Workers actual).

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

# Verificar listener rules del ALB
aws elbv2 describe-listeners --region us-east-1 \
  --load-balancer-arn $(aws elbv2 describe-load-balancers --region us-east-1 \
    --query 'LoadBalancers[?contains(LoadBalancerName,`flexyflow-restaurante-pdn`)].LoadBalancerArn' --output text)
```

> Última revisión: 2026-05-25 (#239 plan inicial).
