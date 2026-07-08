---
title: "Caja y cobros"
description: "El POS integrado: abrir turno, tomar pedidos, cobrar con múltiples métodos de pago, propina, cupones, devoluciones, egresos e impresoras térmicas."
metaTitle: "Caja y cobros — Manual bistro.flexyflow.co"
metaDescription: "Manual de la caja POS de bistro.flexyflow.co: turnos, cobros divididos, devoluciones, egresos, impresoras USB y red, y modo sin conexión."
section: "el día a día"
readingTime: "10 min"
lastUpdated: "8 de julio de 2026"
---

![Selección de caja registradora al iniciar turno](/images/manual/caja.svg "Inicio de turno: eliges qué caja vas a operar.")

## Cajas registradoras

Una sede puede tener **una o varias cajas registradoras**. Si tienes un solo punto de cobro, todo funciona sin configuración extra. Si tienes varios (mostrador principal, barra, caja express), cada caja opera de forma independiente con su propio turno.

Las cajas se crean y administran en *configuración → sedes → cajas registradoras*. Le pones nombre a cada una (Caja 1, Barra, Caja Express) y la activas. Al entrar a la pantalla de caja, el sistema te pregunta cuál caja vas a operar hoy — esa elección se guarda en el dispositivo.

## Abrir el turno

Antes de cobrar, el cajero abre el turno desde **caja → abrir turno** e ingresa el fondo inicial (el efectivo que arrancó en el cajón). Cada caja registradora tiene su propio turno independiente — varias cajas pueden tener turnos abiertos al mismo tiempo en la misma sede.

<div class="callout callout-info">
<p>
<strong>¿Cuánto fondo inicial?</strong> Típicamente la plata que tienes para dar vueltas.
No hay monto mínimo — puede ser $0 si no recibes efectivo.
</p>
</div>

## Tomar un pedido desde la caja

En la vista de caja tienes acceso al menú activo. Agregas los platos al carrito, buscas al cliente por teléfono (o creas uno nuevo) y confirmas el pedido. El pedido entra al tablero de pedidos como cualquier otro y la comanda se manda a la cocina.

## Cobrar un pedido

Cuando el pedido está listo, vas a **cobrar** y ves el detalle del pedido: ítems, subtotal, impuesto, total.

### Métodos de pago

- **Efectivo:** ingresas cuánto recibiste y el sistema calcula el vuelto. La plata queda registrada en el turno.
- **Datáfono (tarjeta):** ingresas la referencia de la transacción del datáfono. Sin referencia no deja cerrar.
- **Transferencia / Nequi / DaviPlata:** ingresas el número de confirmación de la transferencia.
- **Pago dividido:** el cliente paga parte en efectivo y parte con tarjeta, por ejemplo. Le asignas montos a cada método hasta completar el total.

### Propina

Antes de cerrar el cobro, hay campos de propina con sugerencias rápidas (10%, 15%, 20%) o monto libre. La propina se registra aparte — no entra a la base gravable, no genera impuesto y no cuenta como ingreso del negocio en los reportes financieros. Sí aparece en el desglose por método de pago al cerrar caja.

### Cupones

Si el cliente tiene un cupón, lo ingresas en el campo correspondiente antes de cerrar. El sistema verifica la validez, aplica el descuento y recalcula el total. Solo un cupón por pedido.

## Devoluciones (refunds)

Desde cualquier pedido cobrado puedes hacer una devolución total o parcial:

- **Total:** devuelve el monto completo del pedido en el mismo método de pago.
- **Parcial:** seleccionas los ítems específicos que se devuelven.

La devolución crea un nuevo comprobante con monto negativo — el pedido original queda intacto. Para tarjeta o transferencia siempre se exige la referencia del comprobante de devolución.

<div class="callout callout-warn">
<p>
<strong>Efectivo vs digital:</strong> en efectivo la devolución se ejecuta al instante (el
cajero saca la plata del cajón). En tarjeta o transferencia es responsabilidad del operador
gestionar el reembolso con el banco o plataforma — bistro flexy solo lo registra.
</p>
</div>

## Egresos de caja

Los gastos que salen del cajón durante el turno (compra de cambio, pago de proveedor en efectivo, etc.) se registran como **egresos**. Cada egreso lleva: monto, motivo y quién lo autorizó. Quedan en el historial del turno y afectan el balance de cierre.

## Entradas de efectivo

Lo contrario también existe: si entra plata al cajón que **no viene de una venta** — un aporte del dueño, un préstamo para completar el cambio, un ajuste — se registra con el botón **"Entrada"** del panel de caja. Cada entrada lleva monto, categoría y motivo.

Igual que los egresos, las entradas quedan en el historial del turno y se suman al efectivo esperado en el cierre. Así el arqueo cuadra sin tener que "esconder" esa plata en una venta falsa o dejarla por fuera del conteo.

## Cerrar el turno

Al final del día (o del turno) el cajero va a **caja → cerrar turno**. El sistema muestra el total esperado por método de pago según los cobros del turno. El cajero ingresa cuánto contó físicamente en efectivo. La diferencia queda registrada (positiva = sobrante, negativa = faltante).

El cierre es **inmutable** — una vez cerrado no se puede reabrir. Si hay que corregir algo, se hace con un egreso o un cobro nuevo en el siguiente turno.

<div class="callout callout-info">
<p>
<strong>Caja abierta más de 24 horas:</strong> si un turno lleva más de un día abierto, el
panel te muestra un aviso para que hagas el arqueo y lo cierres. Cerrar la caja cada día hace
que el cuadre sea por jornada y que los informes de cierre queden ordenados por fecha.
</p>
</div>

El detalle de cada cierre (ventas por método de pago, entradas, egresos y la diferencia del conteo) queda disponible después en el **informe de cierre por turno**, dentro de la pantalla de *ventas del día*. Ver **métricas**.

## Impresoras térmicas

bistro flexy se conecta con impresoras térmicas ESC/POS por:

- **USB:** directo desde el navegador (Chrome en escritorio). Sin drivers especiales.
- **Red (IP):** a través del agente local de bistro flexy instalado en la computadora del local. El agente es un pequeño programa que vive en segundo plano y recibe las comandas del panel por websocket.

Se configuran en *configuración → impresoras*. Cada impresora puede ser de tipo: cocina, barra, recibos. Las de cocina y barra reciben comandas por categoría del plato; las de recibos imprimen el tiquete del cliente al cerrar el cobro.

## Modo sin conexión

Si se va el internet en pleno turno, la caja sigue funcionando en **modo sin conexión**. Los pedidos y cobros quedan en cola en el navegador y se sincronizan automáticamente cuando vuelve la conexión. Está pensado para horas sin internet, no para días enteros.

<div class="callout callout-info">
<p>
<strong>Precaución con el offline:</strong> si el internet no vuelve antes del cierre de turno,
exporta las pendientes a JSON desde la vista de caja para que no se pierda ningún cobro. El
JSON lo puedes importar manualmente cuando se restablezca la conexión.
</p>
</div>

## Recibos

Después de cobrar, puedes:

- Imprimir el recibo en la térmica.
- Enviarle el recibo al cliente por WhatsApp (si tienes WhatsApp conectado).
- Emitir la factura electrónica DIAN si el cliente la pide con sus datos fiscales. Ver **facturación**.

En caja también puedes agregar el nombre y la **cédula / NIT del cliente** para emitir la factura con sus datos. Si el cliente ya tiene datos guardados (porque compró antes), se precargan automáticamente.

<div class="callout callout-warn">
<p>
El color
Verde en el indicador de estado de impresora
significa que la impresora está lista. Si está en gris, revisa la conexión USB o la IP de red
antes de abrir el turno.
</p>
</div>
