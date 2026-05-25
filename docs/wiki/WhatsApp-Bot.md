# WhatsApp Bot

> Estado: En desarrollo (contratos estables; integración del bot externo evoluciona)
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

El bot de WhatsApp es un **proceso externo** que conversa con clientes finales y crea pedidos en flexyflow. Se autentica con un JWT separado (`BOT_JWT_SECRET`) y consume:

- Endpoints públicos del catálogo (`/api/v1/public/menu/{companyNit}`).
- Endpoints externos del status del restaurante (`/api/external/hours/status`).
- Endpoints públicos de validación de cupones (`/api/v1/coupons/{code}/validate`, `/api/v1/cart/apply-coupon`).
- Crea pedidos vía sus rutas internas (con su `company_nit` embebido en el JWT).

---

## Autenticación del bot

El bot recibe un JWT firmado con `BOT_JWT_SECRET` y TTL más largo (`BOT_JWT_TTL`, default 24h). El payload incluye:

| Campo | Descripción |
|-------|-------------|
| `bot_id` | Identificador del bot |
| `company_nit` | Empresa a la que pertenece el bot |
| `iat`, `exp` | Timestamps |

**Diferencias con el JWT de usuario:**

| Aspecto | JWT usuario | JWT bot |
|---------|-------------|---------|
| Clave | `JWT_SECRET` | `BOT_JWT_SECRET` |
| TTL | 1h | 24h |
| Payload | Encriptado AES-256 | En claro (firmado HS256) |
| Refresh | Auto en `<300s` | Manual cuando expira |
| Middleware | `jwt` | `bot.jwt` |

---

## Endpoints externos

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| `GET` | `/api/external/hours/status` | `bot.jwt` | ¿Está abierto el restaurante? |

```http
GET /api/external/hours/status HTTP/1.1
Authorization: Bearer <BOT_JWT>
```

```http
HTTP/1.1 200 OK
{
  "is_open": true,
  "reason": "Horario regular",
  "next_change": "2026-05-02T22:00:00-05:00"
}
```

---

## Endpoints públicos consumidos por el bot

### Menú público

```http
GET /api/v1/public/menu/900123456-7 HTTP/1.1
Authorization: Bearer <BOT_JWT>
```

```http
HTTP/1.1 200 OK
{
  "menu": {
    "name": "Carta del día",
    "categories": [
      {
        "name": "Platos principales",
        "items": [
          { "id": "uuid", "name": "Bandeja paisa", "price": 32000, "available": true }
        ]
      }
    ]
  }
}
```

Solo devuelve ítems disponibles del menú activo.

### Validar cupón

```http
GET /api/v1/coupons/BIENVENIDO10/validate HTTP/1.1
Authorization: Bearer <BOT_JWT>
```

### Aplicar cupón al carrito

```http
POST /api/v1/cart/apply-coupon HTTP/1.1
Authorization: Bearer <BOT_JWT>
Content-Type: application/json

{
  "code": "BIENVENIDO10",
  "subtotal": 50000,
  "client_phone": "+573001234567"
}
```

---

## Sesión de carrito

Para pedidos multi-turno, el bot usa una sesión de carrito (`cart_sessions`) identificada por un `jwt_jti` único. Permite que el cliente añada/quite ítems durante la conversación antes de confirmar.

| Tabla | Campos |
|-------|--------|
| `cart_sessions` | `jwt_jti` (único), `company_nit`, `client_phone`, `items` (JSON), `coupon_id`, `status` (`active`/`completed`/`abandoned`/`expired`), `expired_at` |

`active` se convierte en `abandoned` por TTL (configurable). Las completadas generan un `Order` real.

---

## Flujo end-to-end

```
1. Cliente escribe al WhatsApp del restaurante
2. Bot recibe el mensaje
3. Bot consulta /api/external/hours/status (¿abierto?)
4. Si abierto:
   a. Bot consulta /api/v1/public/menu/{nit} (catálogo)
   b. Conversa con el cliente, arma carrito en cart_sessions
   c. Aplica cupón (si el cliente lo provee) vía /api/v1/cart/apply-coupon
   d. Confirma pedido → POST /api/v1/orders con items + total + cliente
   e. Notifica al restaurante por el panel (kanban)
5. Si cerrado:
   - Bot responde con horario y next_change
```

---

## Notas de seguridad

- El bot **solo puede operar sobre su `company_nit`**; cualquier intento de cruzar a otra empresa se rechaza en `ValidateBotJwt`.
- El JWT del bot **no es refrescable automáticamente**; debe rotar por gestión externa.
- El menú público es accesible con cualquier JWT válido — incluido el del bot — pero no devuelve datos sensibles (precios sí, costos no).
- Los `cart_sessions` expiran según TTL configurable; las activas durante reinicios persisten en BD.
