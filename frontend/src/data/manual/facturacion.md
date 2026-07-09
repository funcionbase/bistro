---
title: "Facturación"
description: "Acá conviven dos facturaciones: la suscripción que tu negocio le paga a flexyflow y la facturación electrónica DIAN que tu negocio le emite a sus clientes finales."
metaTitle: "Facturación — Manual bistro.flexyflow.co"
metaDescription: "Dos cosas distintas: lo que le pagas a flexyflow por usar el panel (suscripción mensual) y las facturas electrónicas que tu negocio le emite a sus clientes ante la DIAN."
section: "números y reportes"
readingTime: "8 min"
lastUpdated: "8 de julio de 2026"
---

![Facturación del servicio en bistro](/images/manual/facturacion.svg "Facturación: tus facturas del servicio y su estado.")

<div class="callout callout-info">
<p>
<strong>Acá conviven dos facturaciones distintas — no las revuelvas:</strong>
</p>
<ul>
<li>
<strong>La de flexyflow:</strong> lo que tu negocio le paga al panel cada mes por usar el
servicio. Una sola factura mensual.
</li>
<li>
<strong>La electrónica DIAN:</strong> las facturas que tu negocio le emite a sus clientes
finales. Una por venta (o un documento equivalente POS).
</li>
</ul>
</div>

## Suscripción a flexyflow

Lo que pagas para tener el panel funcionando. Una sola suscripción activa por negocio.

### Plan activo

En **facturación** ves la suscripción actual con:

- **Plan contratado.** Todos los negocios arrancan en el *Plan Básico* ($0 COP/mes — la plataforma completa sin costo). El *Plan Plus* ($300.000 COP/mes, IVA 19% incluido, más $10 COP por factura electrónica generada) agrega la facturación electrónica DIAN; se activa contactando a tu asesor. Si te configuraron un descuento por código promocional, lo ves marcado con un ícono de porcentaje.
- **Precio** y ciclo de facturación (mensual).
- **Estado:** activa, pausada o cancelada.
- **Período actual** (las fechas que cubre la próxima factura).
- **Fecha de la próxima factura.** Las facturas se generan automáticamente el **día 1 de cada mes a las 3 de la madrugada** hora Colombia (post-pago: se factura el mes que acaba de pasar).

### Aviso de pagos pendientes

Cuando tienes facturas vencidas, aparece un aviso en la parte superior de la app:

- **Naranja** — mora reciente, una factura vencida.
- **Rojo** — mora prolongada, dos o más facturas vencidas.

**El aviso es informativo — no te bloquea de inmediato.** Si la mora se extiende varios meses (típicamente 3), la cuenta puede pasar a *solo lectura*: sigues entrando y consultando, pero no puedes operar hasta regularizar.

### Cómo registrar un pago

Desde la sección **comprobantes de pago** subes el soporte del pago (transferencia bancaria, consignación) en JPG, PNG o PDF. El equipo de flexyflow lo revisa y aplica el pago a la factura correspondiente.

### Historial de facturas

<table>
<thead>
<tr>
<th>Tipo</th>
<th>Período</th>
<th>Monto</th>
<th>Vencimiento</th>
<th>Estado</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<tr>
<td>Mensual o Prorrateo</td>
<td>Mes facturado</td>
<td>Valor (con icono % si tiene descuento)</td>
<td>Fecha límite</td>
<td>Pendiente, Pagada o Vencida</td>
<td>Ver / Descargar PDF</td>
</tr>
</tbody>
</table>

### Tipos de factura

- **Mensual** — la factura normal del mes corrido.
- **Prorrateo** — cuando tu suscripción empezó a mediados del mes.
- **Nota de crédito** — anulación de una factura previa. Las facturas pagadas o anuladas son inmutables: nunca se editan, todo ajuste queda como un movimiento nuevo.

### Códigos promocionales

Si tienes un código promocional, lo aplicas desde la misma página de facturación. Antes de aplicarlo, el sistema te muestra una **vista previa** con el descuento, los meses que cubre y el ahorro mensual estimado.

<div class="callout callout-warn">
<p>
<strong>Importante:</strong> el cambio de plan o la gestión comercial avanzada se manejan con
el equipo de flexyflow, no desde la app.
</p>
</div>

## Facturación electrónica DIAN

Aquí ya no estamos hablando de lo que pagas tú: estamos hablando de los documentos que **tu negocio le emite a sus clientes** y reporta ante la DIAN.

### Tipos de documento que emite

- **Documento equivalente POS (DEE POS):** el tradicional "tirilla". Es lo que sale por defecto cuando el cliente paga en caja sin pedir factura. Lleva un código único llamado **CUDE**.
- **Factura electrónica de venta (FEV):** cuando el cliente sí pide factura con sus datos. Lleva un código único llamado **CUFE**.
- **Notas crédito** — para anular o devolver cualquiera de las dos. **Nunca** se edita un documento ya emitido — todo cambio queda trazable.
- **Notas débito** — para aumentar el valor de una factura ya emitida.

### Resoluciones DIAN

Para emitir cualquiera de los anteriores, tu negocio necesita al menos **dos resoluciones activas** autorizadas por la DIAN: una para POS y otra para factura electrónica. Cada resolución viene con un prefijo, un rango de numeración y una clave técnica.

Si una resolución se está agotando o por vencerse, te llega una [alerta](/manual/bistro/alertas) para que pidas la siguiente con anticipación.

### Dónde quedan guardados

Cada documento emitido guarda su XML y su PDF (con código QR y CUFE/CUDE) en almacenamiento seguro. Los conservas por **5 años** si tu negocio es persona natural o **10 años** si es persona jurídica — son los plazos de la DIAN. El sistema no permite borrarlos antes de plazo.

<div class="callout callout-success">
<p>
<strong>En resumen:</strong> la <em>suscripción a flexyflow</em> es una factura mensual que tú
recibes y pagas. La <em>facturación electrónica DIAN</em> son miles de documentos pequeños que
tu negocio emite cada mes a sus clientes. La primera la gestionas con tu asesor; la segunda
corre sola en caja una vez que la dejas configurada.
</p>
</div>
