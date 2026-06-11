@component('mail::message')
<div style="margin: 0 0 16px 0;"><span style="display: inline-block; background-color: #FDF0DB; color: #F39C12; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; padding: 6px 12px; border-radius: 9999px;">Alerta interna</span></div>

# Empresa nueva pendiente

Acaba de registrarse una nueva empresa. Requiere tu revisión.

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 24px 0 8px 0; border-collapse: collapse;">
<tr><td style="padding: 14px 0; border-top: 1px solid #E5E5E5; border-bottom: 1px solid #E5E5E5; color: #6B7280; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; width: 38%; vertical-align: top;">Empresa</td><td style="padding: 14px 0; border-top: 1px solid #E5E5E5; border-bottom: 1px solid #E5E5E5; color: #1E232E; font-size: 15px; font-weight: 600; vertical-align: top;">{{ $company->commercial_name }}</td></tr>
<tr><td style="padding: 14px 0; border-bottom: 1px solid #E5E5E5; color: #6B7280; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; vertical-align: top;">Identidad legal</td><td style="padding: 14px 0; border-bottom: 1px solid #E5E5E5; color: #1E232E; font-size: 14px; vertical-align: top;">{{ $company->legal_name ?? '—' }}<br><span style="color: #6B7280; font-size: 13px;">NIT</span> <span style="font-family: 'SF Mono', Menlo, Consolas, monospace;">{{ $company->nit }}</span></td></tr>
<tr><td style="padding: 14px 0; border-bottom: 1px solid #E5E5E5; color: #6B7280; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; vertical-align: top;">Propietario</td><td style="padding: 14px 0; border-bottom: 1px solid #E5E5E5; color: #1E232E; font-size: 14px; vertical-align: top;">{{ $owner->name }}<br><a href="mailto:{{ $owner->email }}" style="color: #0052FF; text-decoration: none; font-size: 13px;">{{ $owner->email }}</a></td></tr>
<tr><td style="padding: 14px 0; border-bottom: 1px solid #E5E5E5; color: #6B7280; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; vertical-align: top;">Recibida</td><td style="padding: 14px 0; border-bottom: 1px solid #E5E5E5; color: #1E232E; font-size: 14px; vertical-align: top;">{{ $company->created_at?->setTimezone('America/Bogota')->format('d/m/Y · H:i') ?? 'ahora' }} <span style="color: #6B7280;">(Bogotá)</span></td></tr>
<tr><td style="padding: 14px 0; border-bottom: 1px solid #E5E5E5; color: #6B7280; font-size: 11px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; vertical-align: top;">Plazo</td><td style="padding: 14px 0; border-bottom: 1px solid #E5E5E5; vertical-align: top;"><span style="display: inline-block; background-color: #FDF0DB; color: #F39C12; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; letter-spacing: 0.02em;">24 horas hábiles</span></td></tr>
</table>

@component('mail::button', ['url' => $dashboardUrl, 'color' => 'dark'])
Revisar en el panel
@endcomponent

@component('mail::subcopy')
Esta alerta llega a `{{ config('mail.ops_alert_address') }}`. Si pasa el plazo sin revisión, el propietario entra al ciclo automático de recordatorios. Configurable vía `MAIL_OPS_ALERT_ADDRESS`.
@endcomponent
@endcomponent
