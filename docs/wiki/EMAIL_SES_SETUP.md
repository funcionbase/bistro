# EMAIL_SES_SETUP — Amazon SES + Cloudflare Email Routing

> Política de envío y recepción de correo electrónico para `flexyflow.co`.
> Fuente de verdad para la configuración SES, DKIM, SPF, DMARC, IAM y
> Cloudflare Routing. Aplicable a `qa` y `pdn`.

## 1. Arquitectura

```
┌─────────────────────────┐                ┌──────────────────────────┐
│  Laravel app (EC2 ASG)  │  HTTPS API     │  Amazon SES (us-east-1)  │
│  driver: ses            │ ─────────────► │  identity: flexyflow.co  │
│  via IAM instance role  │                │  DKIM verificado en CF   │
└─────────────────────────┘                └──────────┬───────────────┘
                                                       │  envia
                                                       ▼
                                           ┌──────────────────────────┐
                                           │  Buzon del destinatario  │
                                           │  (gmail, outlook, etc.)  │
                                           └──────────────────────────┘

┌────────────────────────────┐  MX: route1/2/3.mx.cloudflare.net
│  Quien escribe a           │ ──────────────────────────────────────┐
│  hola@flexyflow.co,        │                                       │
│  soporte@flexyflow.co,     │                                       ▼
│  ops@flexyflow.co, ...     │                            ┌──────────────────────┐
└────────────────────────────┘                            │  Cloudflare Email    │
                                                          │  Routing (gratis)    │
                                                          │  forward → Gmail     │
                                                          └──────────┬───────────┘
                                                                     │
                                                                     ▼
                                                          ┌──────────────────────┐
                                                          │  Gmail del equipo    │
                                                          │  (responde via       │
                                                          │   Send-as + SES)     │
                                                          └──────────────────────┘
```

**SES = solo envío.** No hospeda buzones. La recepción la hace Cloudflare
Email Routing (gratis) reenviando a un Gmail del equipo.

**Por qué SES.** $0.10 USD por 1.000 emails normales, **62.000 emails/mes
gratis** cuando se envía desde EC2 (nuestro stack ASG aplica). Driver `ses`
viene nativo en Laravel 12. Stateless, multi-instancia safe (CLAUDE.md §12).

## 2. Variables de entorno

| Variable                  | Local              | qa/pdn                                    | Notas                                              |
| ------------------------- | ------------------ | ----------------------------------------- | -------------------------------------------------- |
| `MAIL_MAILER`             | `log`              | `ses`                                     | Override via `sync-env-secret.yml` (GH Env Vars).  |
| `MAIL_FROM_ADDRESS`       | `noreply@...`      | `noreply@flexyflow.co`                    | Debe pertenecer al dominio verificado en SES.      |
| `MAIL_FROM_NAME`          | `${APP_NAME}`      | `${APP_NAME}`                             | Hereda del nombre de la app.                       |
| `MAIL_REPLY_TO_ADDRESS`   | `soporte@...`      | `soporte@flexyflow.co`                    | Buzon real (Cloudflare Routing → Gmail).           |
| `AWS_DEFAULT_REGION`      | `us-east-1`        | `us-east-1`                               | Mantener consistente con S3.                       |
| `AWS_ACCESS_KEY_ID`       | (MinIO o vacio)    | **vacio**                                 | IAM instance profile entrega credenciales.         |
| `AWS_SECRET_ACCESS_KEY`   | (MinIO o vacio)    | **vacio**                                 | IAM instance profile entrega credenciales.         |
| `SES_CONFIGURATION_SET`   | vacio              | `flexyflow-default` (cuando Fase 2 activa) | Habilita SNS para bounces/complaints.              |
| `SES_WEBHOOK_SECRET`      | vacio              | secret cuando Fase 2 activa               | Defensa en profundidad sobre firma SNS nativa.     |

## 3. Setup inicial — checklist completo

### 3.1 Verificar el dominio en SES

1. AWS Console → SES (region **us-east-1**) → **Verified identities** → **Create identity**.
2. Tipo: **Domain**, valor: `flexyflow.co`.
3. **Use a custom MAIL FROM domain**: `mail.flexyflow.co` (subdominio).
4. **Behavior on MX failure**: `UseDefaultValue`.
5. **DKIM**: dejar `Easy DKIM` con `RSA_2048_BIT`. SES genera 3 CNAMEs.
6. Click **Create identity**.

### 3.2 Pegar los CNAMEs de DKIM en Cloudflare

En el panel CF de `flexyflow.co` → **DNS** → **Records** → **Add record**:

```
Type   Name                                          Content
─────  ────────────────────────────────────────────  ────────────────────────────────────
CNAME  <token1>._domainkey.flexyflow.co             <token1>.dkim.amazonses.com
CNAME  <token2>._domainkey.flexyflow.co             <token2>.dkim.amazonses.com
CNAME  <token3>._domainkey.flexyflow.co             <token3>.dkim.amazonses.com
```

**Proxy status**: DNS only (gris, NO naranja). DKIM no funciona con proxy CF.

Esperar 5–15 min y refrescar SES; los 3 CNAMEs deben aparecer en **Verified**.

### 3.3 Custom MAIL FROM domain

SES exige 1 MX + 1 SPF TXT para `mail.flexyflow.co`:

```
Type   Name                       Content
─────  ─────────────────────────  ────────────────────────────────────
MX     mail.flexyflow.co          10 feedback-smtp.us-east-1.amazonses.com
TXT    mail.flexyflow.co          "v=spf1 include:amazonses.com -all"
```

Sin esto, los correos siguen saliendo pero el header `From` muestra
`...@amazonses.com` y el alineamiento DMARC falla.

### 3.4 SPF y DMARC del dominio raíz

SPF para el dominio raíz (cuando enviás desde `noreply@flexyflow.co`):

```
Type   Name              Content
─────  ────────────────  ────────────────────────────────────
TXT    flexyflow.co      "v=spf1 include:amazonses.com -all"
```

> Si ya existe un TXT SPF para `flexyflow.co` (p.ej. Google Workspace),
> **NO crear otro** — modificar el existente para incluir ambos:
> `"v=spf1 include:amazonses.com include:_spf.google.com -all"`.

DMARC en modo observación primero:

```
Type   Name                    Content
─────  ──────────────────────  ───────────────────────────────────────────────────────
TXT    _dmarc.flexyflow.co     "v=DMARC1; p=none; rua=mailto:dmarc@flexyflow.co; fo=1"
```

`p=none` recibe reportes pero NO rechaza nada. Después de 1–2 semanas de
revisar reportes y confirmar que no hay falsos positivos, subir a
`p=quarantine` y eventualmente `p=reject`.

### 3.5 IAM policy para el instance profile del ASG

Política mínima — pegar al rol del ASG:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowSendFromFlexyflowIdentity",
      "Effect": "Allow",
      "Action": [
        "ses:SendEmail",
        "ses:SendRawEmail"
      ],
      "Resource": "arn:aws:ses:us-east-1:<ACCOUNT_ID>:identity/flexyflow.co",
      "Condition": {
        "StringEquals": {
          "ses:FromAddress": [
            "noreply@flexyflow.co"
          ]
        }
      }
    }
  ]
}
```

Reemplazar `<ACCOUNT_ID>` por el AWS Account ID. La condición restringe a
`FromAddress=noreply@flexyflow.co` — si después agregás otros remitentes
(`facturacion@`, `soporte@`), extender el array.

### 3.6 Salir del sandbox de SES

Por default SES solo deja enviar a direcciones verificadas (sandbox).
Para enviar a cualquier destinatario:

1. SES Console → **Account dashboard** → **Request production access**.
2. Llenar:
   - **Use case**: Transactional
   - **Website**: `https://panel.flexyflow.co`
   - **Description**: "flexyflow es un SaaS de gestión para restaurantes en
     Colombia. Enviamos correos transaccionales: confirmación de cuenta,
     reset de contraseña, facturas mensuales, notificaciones operativas.
     Bounce y complaint rate monitoreados via SNS. Audiencia: dueños y
     equipo de restaurantes registrados, opt-in implícito al crear cuenta."
   - **How to handle bounces/complaints**: "Procesados via SNS subscription;
     direcciones con bounce permanente o complaint van a suppression list
     interna y se excluyen de envíos futuros."
3. Send. AWS responde en 24h hábiles típicamente.

Mientras estás en sandbox, podés verificar emails individuales para
testear: SES → **Verified identities** → Create identity (Email).

### 3.7 Configurar Cloudflare Email Routing

1. CF panel de `flexyflow.co` → **Email** → **Email Routing** → **Enable**.
2. CF crea automáticamente 3 MX + 1 TXT SPF. Aceptar.
3. **Destination addresses** → Add → ingresar tu Gmail personal → confirmar
   el link que llega al Gmail.
4. **Routing rules** → crear:
   - `hola@flexyflow.co` → forward a Gmail
   - `soporte@flexyflow.co` → forward a Gmail
   - `ops@flexyflow.co` → forward a Gmail
   - `dmarc@flexyflow.co` → forward a Gmail (recibe reportes DMARC)
   - **Catch-all** (opcional): cualquier `*@flexyflow.co` → forward
5. Si querés responder *desde* `hola@flexyflow.co` con Gmail:
   - Gmail → Settings → Accounts → **Send mail as** → Add another email.
   - SMTP: `email-smtp.us-east-1.amazonaws.com` puerto 587 STARTTLS.
   - Credenciales: generar **SES SMTP credentials** en AWS Console (IAM →
     usuario dedicado con `ses:SendRawEmail` o usar el wizard de SES SMTP).
   - Verificar con código que SES envía al Gmail.

> **Conflicto SPF**: si ya tenías un TXT SPF y CF crea uno propio, hay que
> mergearlos. Solo puede haber UN TXT SPF en `flexyflow.co`. Combinar:
> `"v=spf1 include:_spf.mx.cloudflare.net include:amazonses.com -all"`.

## 4. Configurar GitHub Environments

### qa

GH Settings → Environments → **qa** → Add variables:

```
MAIL_MAILER=ses
MAIL_FROM_ADDRESS=noreply@flexyflow.co
MAIL_REPLY_TO_ADDRESS=soporte@flexyflow.co
```

(Borrar `MAIL_USERNAME` / `MAIL_PASSWORD` si existen — legacy SMTP.)

### pdn

Idéntico a qa. Las variables son las mismas porque enviamos al mismo
identity. Si en algún momento se separan los buckets/identities por
entorno (`noreply@qa.flexyflow.co` vs `noreply@flexyflow.co`), ajustar
`MAIL_FROM_ADDRESS` por entorno.

Tras setear las vars, correr **Actions** → `Sync GH Environment → AWS
Secrets Manager (.env)` → seleccionar entorno → Run. Después refrescar
el ASG o re-launchar EC2 para que tome el nuevo `.env`.

## 5. Probar que funciona

### Local (driver `log`)

```bash
cd application/backend
php artisan tinker --execute '
    Mail::raw("Test SES setup", function ($m) {
        $m->to("cristian@gmail.com")->subject("Test desde local");
    });
'
tail -n 50 storage/logs/laravel.log
```

Debe aparecer el correo serializado en el log — NO se envía nada al exterior.

### QA (driver `ses`, sandbox)

Verificar primero `cristian@gmail.com` (o el destinatario que vayas a usar)
en SES → Verified identities → Create identity (Email) → confirmar link.

SSH al EC2 del ASG y:

```bash
cd /var/www/flexyflow.restaurante/application/backend
sudo -u www-data php artisan tinker --execute '
    Mail::raw("Test SES desde QA", function ($m) {
        $m->to("cristian@gmail.com")->subject("Test QA → SES");
    });
'
```

Debe llegar al Gmail (revisar también la carpeta de spam la primera vez).

### Verificar config cargada

```bash
php artisan config:show mail.default        # ses
php artisan config:show mail.from.address   # noreply@flexyflow.co
php artisan config:show mail.reply_to       # array con soporte@flexyflow.co
php artisan config:show services.ses        # region us-east-1, options con ConfigurationSetName si aplica
```

## 6. Troubleshooting

### "MessageRejected: Email address is not verified"

Estás en sandbox. Verificá el destinatario en SES (Verified identities →
Email) o pedí salida de sandbox (§3.6).

### "MessageRejected: From address is not verified"

`MAIL_FROM_ADDRESS` apunta a un dominio que no está verificado en SES, o
los CNAMEs DKIM no propagaron. Revisar `Verified identities` → estado debe
ser **Verified**.

### Llega al spam

1. Confirmar **DKIM = Pass** y **SPF = Pass** en el header del correo
   recibido (Gmail → ⋮ → "Show original").
2. Si SPF falla y el `From` muestra `amazonses.com`: falta el Custom MAIL
   FROM (§3.3).
3. Confirmar DMARC: `dig _dmarc.flexyflow.co TXT`.
4. Reputación de remitente nueva — los primeros envíos a Gmail pueden caer
   en spam hasta que el dominio acumule historial. Pedir al equipo que
   marquen "No es spam" las primeras veces.
5. Verificar reportes en SES → **Reputation metrics**: bounce rate <5%,
   complaint rate <0.1%.

### "Could not resolve host: email.us-east-1.amazonaws.com"

EC2 sin salida a internet o sin endpoint VPC para SES. Verificar Security
Group + Route Table del subnet del ASG.

### IAM `AccessDenied` al llamar `ses:SendEmail`

Política del instance profile no incluye el permiso o la condición
`ses:FromAddress` excluye el remitente. Revisar §3.5.

### Bounces / complaints aumentan

Es señal de que hay direcciones inválidas en la base. La Fase 2 (próximo
PR) procesa estos eventos vía SNS y los persiste en `email_suppressions`
para auto-excluirlos. Mientras tanto, monitorear en SES → **Account
dashboard** y limpiar a mano si superan el umbral (5% bounces, 0.1%
complaints).

## 7. Reglas N-instance safety (CLAUDE.md §12)

El driver `ses` hace una llamada HTTP API a SES por cada envío — no hay
estado local, no hay coordinación entre instancias del ASG. ✅

Las Notifications con `ShouldQueue` se encolan en `database` (Postgres) y
las procesa el worker `php artisan queue:listen`. Cada job corre en una
sola instancia (lock en `failed_jobs`). ✅

Cuando llegue la Fase 2, el SNS topic publica el bounce a un endpoint
HTTPS público del app — múltiples instancias detrás del ALB pueden
recibir el mismo evento si SNS reintenta; la idempotencia se garantiza
por `webhook_events.event_id` único + `INSERT ... ON CONFLICT DO NOTHING`
en `email_suppressions` (CLAUDE.md §13 rules de inmutabilidad). ✅

## 8. Impacto RBAC

**Sin impacto en Fase 1.** No cambia permisos, roles, middlewares ni
flujo de tenancy. Los Notifications existentes ya usan `Notifiable`
sobre `User` que pertenece a una `Company` — el RBAC se aplica al
*registrar* la notificación, no al transport.

**Fase 2** introducirá un endpoint público `/api/v1/webhooks/ses-notifications`
sin JWT (igual patrón que `/api/v1/webhooks/whatsapp`). La autenticidad
se verifica con la firma SNS de AWS (`x509` cert + RSA-SHA1 sobre el
canonical string del payload). No requiere middleware de tenancy porque
las suppressions son globales (no per-company).

## 9. Reglas contables (CLAUDE.md §13)

Las Notifications transaccionales que tocan dinero (`InvoiceGenerated`,
`PaymentProofSubmitted`, `CompanyPaymentBlocked`) leen sus datos de
`invoices` / `payment_receipts`, que son tablas inmutables. El envío del
correo no muta ningún registro contable — solo notifica.

Si una notification adjunta el PDF de una factura, el PDF se genera desde
la *última* versión persistida del receipt (no se regenera dinámico con
datos potencialmente alterados). DIAN exige retención de 10 años para
documentos de personas jurídicas; los PDFs viven en S3 (`AWS_BUCKET_DOCUMENTS`,
bucket privado) y nunca se borran.

## 10. Roadmap

### Fase 1 ✅ (este PR)
- Driver SES configurado vía IAM instance profile.
- Variables documentadas.
- Custom MAIL FROM, DKIM, SPF, DMARC.
- Cloudflare Email Routing para recepción.

### Fase 2 (próximo PR)
- Migration `email_suppressions`.
- Webhook `POST /api/v1/webhooks/ses-notifications` con verificación SNS.
- Listener en `MessageSending` que aborta si destinatario está suppressed.
- SES Configuration Set `flexyflow-default` con destino SNS para Bounce
  + Complaint.
- Auditoría con `AuditService::log('email.suppressed', ...)`.

### Fase 3 (incluida en este PR — branding de templates)
- Templates `vendor/mail/html/*` publicadas y personalizadas con logo
  flexyflow, paleta del DS, footer legal CO (razón social, dirección,
  contacto, link a política de privacidad).

### #226 — Welcome email idempotente (registro exitoso)

Correo transaccional al usuario tras `CompanyEnrollmentController::store`.
Orquestado por `SendCompanyRegistrationWelcomeEmailJob` (`ShouldQueue` +
`ShouldBeUnique`, `uniqueId="welcome_email:{user_id}:{company_nit}"`,
`uniqueFor=86400`). Cuatro capas de protección contra envíos duplicados en
el ASG N-instance — ver `application/backend/constants/AUDIT_EVENTS.md`
sección "Enrolamiento" y el job propio para el detalle. Eventos auditados:
`enrollment.welcome_email_sent` (OK), `enrollment.welcome_email_failed`
(tras agotar `tries=3`).

`config/queue.php` ahora tiene `after_commit: true` global en el driver
`database`: cualquier `dispatch` dentro de una `DB::transaction` sólo se
persiste si la transacción commitea OK. Si algún call site necesita el
comportamiento previo, usar `->beforeCommit()` puntual.

### #227 — Correo de invitación de usuario (idempotente)

Correo transaccional al invitado tras `InvitationController::store`.
Orquestado por `SendUserInvitationEmailJob` (`ShouldQueue` + `ShouldBeUnique`,
`uniqueId="invitation_email:{invitation_id}"`, `uniqueFor=3600`). Mismas
cuatro capas que `SendCompanyRegistrationWelcomeEmailJob`, pero con
`uniqueFor` de 1 hora para permitir reenvíos manuales legítimos via
`POST /api/v1/invitations/{id}/resend`. Eventos auditados:
`invitation.email_sent` (OK), `invitation.email_failed` (tras agotar
`tries=3`), `invitation.resent` (reencolado manual). Ver
`application/backend/constants/AUDIT_EVENTS.md` sección "Invitaciones a
usuarios de empresa (#227)".

El correo NO incluye token en URL: la aceptación es automática vía
email auto-match en `InvitedEnrollmentController` cuando el invitado
autentica con el mismo correo que recibió la invitación. El CTA va a
`/login` sin parámetros sensibles, lo que evita exposición del token de
64 bytes en logs/proxies/correo cacheado. El token sigue persistido en
`company_invitations.token` como respaldo de auditoría.

## 11. Referencias

- AWS SES Developer Guide: <https://docs.aws.amazon.com/ses/latest/dg/>
- SES SMTP credentials (para Gmail Send-as): <https://docs.aws.amazon.com/ses/latest/dg/smtp-credentials.html>
- DMARC Inspector (validar reportes): <https://dmarcian.com/dmarc-inspector/>
- Cloudflare Email Routing docs: <https://developers.cloudflare.com/email-routing/>
- Laravel Mail (Laravel 12): `php artisan docs mail`
