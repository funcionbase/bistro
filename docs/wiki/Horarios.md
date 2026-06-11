# Horarios Comerciales

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

El módulo de horarios determina si el restaurante está abierto en un instante dado. Se compone de:

1. **Horarios base** (`business_hours`): por día de la semana, opcionalmente con varios bloques.
2. **Excepciones** (`business_hour_exceptions`): fecha específica que sobrescribe el horario base (cierres temporales, fechas especiales, ampliaciones).

El bot externo consulta el endpoint público `/api/external/hours/status` para decidir si acepta pedidos.

---

## Modelo

`business_hours`:

| Campo | Descripción |
|-------|-------------|
| `company_nit` | FK |
| `day_of_week` | `0`=domingo … `6`=sábado (convención Carbon/JS — **diferente** de ISO 8601) |
| `open_time`, `close_time` | `HH:MM` |
| `is_enabled` | Si es `false`, el día está cerrado |

`business_hour_exceptions`:

| Campo | Descripción |
|-------|-------------|
| `exception_date` | `YYYY-MM-DD` |
| `is_open` | Si `false`, cerrado todo el día |
| `open_time`, `close_time` | Solo si `is_open=true` |
| `reason` | Motivo libre |

Las excepciones tienen **precedencia absoluta** sobre el horario base.

---

## Endpoints

### Internos (con `company.access`)

| Método | Ruta | Permiso |
|--------|------|---------|
| `GET` | `/api/v1/hours` | `hours.read,read` |
| `GET` | `/api/v1/hours/status` | `hours.read,read` |
| `PUT` | `/api/v1/hours` | `hours.update,update` |
| `GET` | `/api/v1/hours/exceptions` | `hours.read,read` |
| `POST` | `/api/v1/hours/exceptions` | `hours.update,create` |
| `PUT` | `/api/v1/hours/exceptions/{id}` | `hours.update,update` |
| `DELETE` | `/api/v1/hours/exceptions/{id}` | `hours.update,delete` |

### Externo (sin `company.access`)

| Método | Ruta | Auth | Descripción |
|--------|------|------|-------------|
| `GET` | `/api/external/hours/status` | `bot.jwt` | Status para el bot, no requiere ser miembro |

---

## Ejemplos

### Definir horarios base

```http
PUT /api/v1/hours HTTP/1.1
Content-Type: application/json

{
  "hours": [
    { "day_of_week": 1, "open_time": "11:00", "close_time": "22:00", "is_enabled": true },
    { "day_of_week": 2, "open_time": "11:00", "close_time": "22:00", "is_enabled": true },
    { "day_of_week": 3, "open_time": "11:00", "close_time": "22:00", "is_enabled": true },
    { "day_of_week": 4, "open_time": "11:00", "close_time": "22:00", "is_enabled": true },
    { "day_of_week": 5, "open_time": "11:00", "close_time": "23:00", "is_enabled": true },
    { "day_of_week": 6, "open_time": "12:00", "close_time": "23:00", "is_enabled": true },
    { "day_of_week": 0, "open_time": null, "close_time": null, "is_enabled": false }
  ]
}
```

### Crear excepción

```http
POST /api/v1/hours/exceptions HTTP/1.1
Content-Type: application/json

{
  "exception_date": "2026-12-25",
  "is_open": false,
  "reason": "Navidad"
}
```

### Consultar status

```http
GET /api/v1/hours/status HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "is_open": true,
  "reason": "Horario regular",
  "next_change": "2026-05-02T22:00:00-05:00"
}
```

`next_change` es el siguiente cambio relevante (cierre si está abierto; próxima apertura si está cerrado).

### Status para el bot (externo)

```http
GET /api/external/hours/status HTTP/1.1
Authorization: Bearer <BOT_JWT>
```

```http
HTTP/1.1 200 OK
{ "is_open": true, "reason": "Horario regular", "next_change": "..." }
```

---

## Configuración

`bistro/backend/config/business-hours.php`:

| Clave | Default | Descripción |
|-------|---------|-------------|
| `timezone` | `env('BUSINESS_HOURS_TIMEZONE', env('APP_TIMEZONE', 'UTC'))` | TZ usado para evaluar. En PDN/QA debe apuntar a `America/Bogota` (todas las empresas operan en CO, UTC-5 sin DST) |

---

## Notas de seguridad

- El endpoint externo (`/api/external/hours/status`) verifica solo la firma del `bot.jwt`; no valida membresía. La empresa se identifica por el campo `company_nit` embebido en ese JWT.
- Las excepciones siempre ganan al horario base; útil para cerrar por mantenimiento sin tocar la configuración semanal.
